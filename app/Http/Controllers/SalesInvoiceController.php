<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Requests\ConfirmSalesInvoiceRequest;
use App\Http\Requests\SalesInvoiceRequest;
use App\Http\Requests\UpdateSalesInvoiceRequest;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\PrintStation;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Inventory\InventoryTraceabilityService;
use App\Services\Invoices\InvoiceDeletionService;
use App\Services\Printing\PrintSettingsService;
use App\Services\Sales\CustomerReceivableService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use App\Support\BusinessClock;
use App\Support\Decimal;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageSales(), 403);

        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $status = $validated['status'] ?? null;
        $base = Invoice::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('type', InvoiceType::Sales);
        $invoices = (clone $base)
            ->with('customer.party')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('invoice_number', 'like', $term)
                        ->orWhereHas('customer.party', function (Builder $query) use ($term): void {
                            $query
                                ->where('name', 'like', $term)
                                ->orWhere('code', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Invoice $invoice): array {
                $party = $invoice->partyIdentity();

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'status' => $invoice->status->value,
                    'total' => $invoice->total,
                    'customer' => [
                        'name' => $party['name'],
                        'code' => $party['code'],
                        'phone' => $party['phone'],
                        'legacy_current' => $party['legacy_current'] ?? false,
                    ],
                ];
            });

        return Inertia::render('sales/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', InvoiceStatus::Draft)->count(),
                'open' => (clone $base)->whereIn('status', [
                    InvoiceStatus::Confirmed,
                    InvoiceStatus::PartiallyPaid,
                ])->count(),
                'sales_total' => (string) (clone $base)->whereIn('status', [
                    InvoiceStatus::Confirmed,
                    InvoiceStatus::PartiallyPaid,
                    InvoiceStatus::Paid,
                ])->sum('total'),
            ],
        ]);
    }

    public function create(Request $request, BusinessClock $clock): Response
    {
        abort_unless($request->user()?->canManageSales(), 403);

        return $this->renderSalesForm(null, $clock);
    }

    public function edit(Request $request, Invoice $invoice): Response
    {
        abort_unless($request->user()?->canManageSales(), 403);
        $this->assertDraftSalesInvoice($invoice);

        return $this->renderSalesForm($invoice, app(BusinessClock::class));
    }

    public function store(SalesInvoiceRequest $request, SalesService $sales): RedirectResponse
    {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $data = $request->validated();

        try {
            $invoice = $sales->createDraft(
                [
                    'store_id' => $store->id,
                    'branch_id' => $branch->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'customer_id' => (int) $data['customer_id'],
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()->id,
                ],
                $data['items'],
                $data['submission_token'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'sale' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس فروش {$invoice->invoice_number} ایجاد شد.",
        ]);

        return to_route('sales.show', $invoice);
    }

    public function update(
        UpdateSalesInvoiceRequest $request,
        Invoice $invoice,
        SalesService $sales,
    ): RedirectResponse {
        $this->assertDraftSalesInvoice($invoice);
        $data = $request->validated();

        try {
            $updated = $sales->updateDraft(
                $invoice,
                [
                    'store_id' => $invoice->store_id,
                    'branch_id' => $invoice->branch_id,
                    'fiscal_year_id' => $invoice->fiscal_year_id,
                    'customer_id' => (int) $data['customer_id'],
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $invoice->created_by,
                ],
                $data['items'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'sale' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس فروش {$updated->invoice_number} ذخیره شد.",
        ]);

        return to_route('sales.show', $updated);
    }

    public function show(
        Request $request,
        Invoice $invoice,
        CustomerReceivableService $receivables,
        SalesReturnService $returns,
        PrintSettingsService $printSettings,
        InventoryTraceabilityService $traceability,
    ): Response {
        abort_unless($request->user()?->canManageSales(), 403);
        $this->assertSalesInvoice($invoice);
        $store = $this->mainStore();
        $invoice->load([
            'items',
            'customer.party',
            'branch',
            'childInvoices' => fn ($query) => $query
                ->where('type', InvoiceType::SalesReturn)
                ->orderByDesc('id'),
        ]);
        $trace = $traceability->forSource($invoice);
        $party = $invoice->partyIdentity();

        return Inertia::render('sales/Show', [
            'can_confirm' => $request->user()->canConfirmSales(),
            'can_receive' => $request->user()->canManageReceipts(),
            'can_return' => $request->user()->canConfirmSales()
                && $returns->returnableItems($invoice) !== [],
            'has_print_station' => PrintStation::query()
                ->where('store_id', $invoice->store_id)
                ->where('branch_id', $invoice->branch_id)
                ->online()
                ->exists(),
            'outstanding' => $receivables->outstandingForInvoice($invoice),
            'seller' => [
                'name' => $store->legal_name ?: $store->name,
                'branch_name' => $invoice->branch->name,
                'phone' => $store->phone,
                'email' => $store->email,
                'national_id' => $store->national_id,
                'tax_id' => $store->tax_id,
                'address' => $store->address,
            ],
            'print_settings' => $printSettings->forBranch($invoice->branch),
            'returns' => $invoice->childInvoices->map(fn (Invoice $return): array => [
                'id' => $return->id,
                'invoice_number' => $return->invoice_number,
                'invoice_date' => $return->invoice_date->toDateString(),
                'status' => $return->status->value,
                'total' => $return->total,
            ])->values(),
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status->value,
                'type' => $invoice->type->value,
                'subtotal' => $invoice->subtotal,
                'discount_total' => $invoice->discount_total,
                'tax_total' => $invoice->tax_total,
                'total' => $invoice->total,
                'notes' => $invoice->notes,
                'customer' => [
                    'id' => $invoice->customer->id,
                    'name' => $party['name'],
                    'code' => $party['code'],
                    'phone' => $party['phone'],
                    'legacy_current' => $party['legacy_current'] ?? false,
                    'credit_limit' => $invoice->customer->credit_limit,
                ],
                'items' => $invoice->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => (int) $item->product_id,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                    'trace' => $trace->get($item->product_id, []),
                ])->values(),
            ],
            'warehouses' => $invoice->status === InvoiceStatus::Draft
                ? $invoice->branch->warehouses()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_default'])
                : [],
        ]);
    }

    public function confirm(
        ConfirmSalesInvoiceRequest $request,
        Invoice $invoice,
        SalesService $sales,
    ): RedirectResponse {
        $this->assertSalesInvoice($invoice);

        try {
            $sales->confirm($invoice, (int) $request->validated('warehouse_id'), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'sale' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "فاکتور فروش {$invoice->invoice_number} تأیید و ثبت شد.",
        ]);

        return to_route('sales.show', $invoice);
    }

    public function destroy(
        Request $request,
        Invoice $invoice,
        InvoiceDeletionService $deletions,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageSales(), 403);
        $this->assertSalesInvoice($invoice);

        try {
            $deletions->deleteDraft(
                $invoice,
                InvoiceType::Sales,
                $this->mainStore()->id,
                $this->defaultBranch($this->mainStore())->id,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'sale' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'پیش‌نویس فاکتور فروش حذف شد.',
        ]);

        return to_route('sales.index');
    }

    private function renderSalesForm(?Invoice $invoice, BusinessClock $clock): Response
    {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $invoice !== null
            ? $invoice->fiscalYear
            : $this->currentFiscalYear($store);
        $invoiceDate = $invoice?->invoice_date->toDateString() ?? max(
            $fiscalYear->starts_on->toDateString(),
            min(
                $fiscalYear->ends_on->toDateString(),
                $clock->today($store)->toDateString(),
            ),
        );
        $warehouses = $branch->warehouses()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
        $defaultWarehouse = $warehouses->firstWhere('is_default', true) ?? $warehouses->first();
        $warehouseId = $defaultWarehouse?->id;
        $invoice?->load(['customer.party', 'items.product', 'fiscalYear']);
        $stockQuantities = $invoice !== null && $warehouseId !== null
            ? InventoryBalance::query()
                ->where('store_id', $store->id)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $invoice->items->pluck('product_id'))
                ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as stock_quantity')
                ->groupBy('product_id')
                ->pluck('stock_quantity', 'product_id')
            : collect();
        $party = $invoice === null ? null : $invoice->partyIdentity();

        return Inertia::render('sales/Create', [
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'notes' => $invoice->notes,
                'customer' => [
                    'id' => (int) $invoice->customer_id,
                    'code' => (string) $party['code'],
                    'name' => (string) $party['name'],
                    'phone' => $party['phone'],
                    'credit_limit' => $invoice->customer->credit_limit,
                    'payment_terms' => $invoice->customer->payment_terms,
                ],
                'items' => $invoice->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => (int) $item->product_id,
                    'code' => $item->product_code ?? '',
                    'name' => $item->product_name,
                    'unit' => $item->unit,
                    'stock_quantity' => Decimal::normalize(
                        (string) ($stockQuantities->get($item->product_id) ?? '0'),
                        Decimal::QUANTITY_SCALE,
                    ),
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate' => $item->tax_rate,
                    'item_type' => $item->product !== null
                        ? $item->product->item_type
                        : 'stock',
                ])->values()->all(),
            ],
            'defaults' => [
                'invoice_date' => $invoiceDate,
                'due_date' => $invoice?->due_date?->toDateString() ?? '',
                'warehouse_id' => $warehouseId,
                'submission_token' => (string) Str::uuid(),
                'fiscal_year' => [
                    'title' => $fiscalYear->title,
                    'starts_on' => $fiscalYear->starts_on->toDateString(),
                    'ends_on' => $fiscalYear->ends_on->toDateString(),
                ],
            ],
            'warehouses' => $warehouses,
        ]);
    }

    private function assertDraftSalesInvoice(Invoice $invoice): void
    {
        $this->assertSalesInvoice($invoice);
        abort_unless($invoice->status === InvoiceStatus::Draft, 404);
    }

    private function assertSalesInvoice(Invoice $invoice): void
    {
        abort_unless(
            $invoice->type === InvoiceType::Sales
            && $invoice->store_id === $this->mainStore()->id
            && $invoice->branch_id === $this->defaultBranch($this->mainStore())->id,
            404,
        );
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function defaultBranch(Store $store): Branch
    {
        return app(BranchContextService::class)->active(request()->user(), $store);
    }

    private function currentFiscalYear(Store $store): FiscalYear
    {
        return $store->fiscalYears()
            ->where('is_current', true)
            ->firstOrFail();
    }
}
