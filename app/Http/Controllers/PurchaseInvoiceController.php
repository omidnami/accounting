<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Requests\ConfirmPurchaseInvoiceRequest;
use App\Http\Requests\PurchaseInvoiceRequest;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Store;
use App\Services\Purchasing\PurchaseService;
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

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManagePurchases(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $status = $validated['status'] ?? null;
        $base = Invoice::query()
            ->where('store_id', $store->id)
            ->where('type', InvoiceType::Purchase);
        $invoices = (clone $base)
            ->with('supplier.party:id,code,name,phone')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('invoice_number', 'like', $term)
                        ->orWhereHas('supplier.party', function (Builder $query) use ($term): void {
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
            ->through(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status->value,
                'total' => $invoice->total,
                'supplier' => [
                    'name' => $invoice->supplier->party->name,
                    'code' => $invoice->supplier->party->code,
                    'phone' => $invoice->supplier->party->phone,
                ],
            ]);

        return Inertia::render('purchases/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', InvoiceStatus::Draft)->count(),
                'payable' => (clone $base)->whereIn('status', [
                    InvoiceStatus::Confirmed,
                    InvoiceStatus::PartiallyPaid,
                ])->count(),
                'purchase_total' => (string) (clone $base)->whereIn('status', [
                    InvoiceStatus::Confirmed,
                    InvoiceStatus::PartiallyPaid,
                    InvoiceStatus::Paid,
                ])->sum('total'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManagePurchases(), 403);

        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $invoiceDate = max(
            $fiscalYear->starts_on->toDateString(),
            min($fiscalYear->ends_on->toDateString(), now()->toImmutable()->toDateString()),
        );

        return Inertia::render('purchases/Create', [
            'defaults' => [
                'invoice_date' => $invoiceDate,
                'due_date' => '',
                'warehouse_id' => $branch->warehouses()
                    ->where('is_active', true)
                    ->where('is_default', true)
                    ->value('id'),
                'submission_token' => (string) Str::uuid(),
                'fiscal_year' => [
                    'title' => $fiscalYear->title,
                    'starts_on' => $fiscalYear->starts_on->toDateString(),
                    'ends_on' => $fiscalYear->ends_on->toDateString(),
                ],
            ],
            'warehouses' => $branch->warehouses()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
        ]);
    }

    public function store(PurchaseInvoiceRequest $request, PurchaseService $purchases): RedirectResponse
    {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $data = $request->validated();

        try {
            $invoice = $purchases->createDraft(
                [
                    'store_id' => $store->id,
                    'branch_id' => $branch->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'supplier_id' => (int) $data['supplier_id'],
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
                'purchase' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس خرید {$invoice->invoice_number} ایجاد شد.",
        ]);

        return to_route('purchases.show', $invoice);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        abort_unless($request->user()?->canManagePurchases(), 403);
        $this->assertPurchaseInvoice($invoice);
        $invoice->load(['items', 'supplier.party']);

        return Inertia::render('purchases/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status->value,
                'subtotal' => $invoice->subtotal,
                'discount_total' => $invoice->discount_total,
                'tax_total' => $invoice->tax_total,
                'total' => $invoice->total,
                'notes' => $invoice->notes,
                'supplier' => [
                    'name' => $invoice->supplier->party->name,
                    'code' => $invoice->supplier->party->code,
                    'phone' => $invoice->supplier->party->phone,
                ],
                'items' => $invoice->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
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
        ConfirmPurchaseInvoiceRequest $request,
        Invoice $invoice,
        PurchaseService $purchases,
    ): RedirectResponse {
        $this->assertPurchaseInvoice($invoice);

        try {
            $purchases->confirm(
                $invoice,
                (int) $request->validated('warehouse_id'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'purchase' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "فاکتور خرید {$invoice->invoice_number} تأیید و به انبار وارد شد.",
        ]);

        return to_route('purchases.show', $invoice);
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->canManagePurchases(), 403);
        $this->assertPurchaseInvoice($invoice);

        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'purchase' => 'فقط پیش‌نویس فاکتور خرید قابل حذف است.',
            ]);
        }

        $invoice->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'پیش‌نویس فاکتور خرید حذف شد.',
        ]);

        return to_route('purchases.index');
    }

    private function assertPurchaseInvoice(Invoice $invoice): void
    {
        abort_unless(
            $invoice->type === InvoiceType::Purchase
            && $invoice->store_id === $this->mainStore()->id,
            404,
        );
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function defaultBranch(Store $store): Branch
    {
        return $store->defaultBranch()
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function currentFiscalYear(Store $store): FiscalYear
    {
        return $store->fiscalYears()
            ->where('is_current', true)
            ->firstOrFail();
    }
}
