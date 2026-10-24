<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Requests\ConfirmPurchaseReturnRequest;
use App\Http\Requests\ConfirmSalesReturnRequest;
use App\Http\Requests\StorePurchaseReturnRequest;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Sales\SalesReturnService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceReturnController extends Controller
{
    public function createSales(
        Request $request,
        Invoice $invoice,
        SalesReturnService $returns,
    ): Response {
        abort_unless($request->user()?->canConfirmSales(), 403);
        $this->assertOriginalInvoice($invoice, InvoiceType::Sales);

        return $this->renderCreate(
            'sales',
            $invoice,
            $returns->returnableItems($invoice),
        );
    }

    public function storeSales(
        StoreSalesReturnRequest $request,
        Invoice $invoice,
        SalesReturnService $returns,
    ): RedirectResponse {
        $this->assertOriginalInvoice($invoice, InvoiceType::Sales);
        $data = $request->validated();

        try {
            $return = $returns->createDraft(
                $invoice,
                $data['items'],
                $data['return_date'],
                $request->user()->id,
                $data['submission_token'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'return' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس برگشت فروش {$return->invoice_number} ایجاد شد.",
        ]);

        return to_route('sales.returns.show', $return);
    }

    public function showSales(Request $request, Invoice $return): Response
    {
        abort_unless($request->user()?->canConfirmSales(), 403);
        $this->assertReturnInvoice($return, InvoiceType::SalesReturn);

        return $this->renderShow('sales', $return);
    }

    public function confirmSales(
        ConfirmSalesReturnRequest $request,
        Invoice $return,
        SalesReturnService $returns,
    ): RedirectResponse {
        $this->assertReturnInvoice($return, InvoiceType::SalesReturn);

        try {
            $returns->confirm(
                $return,
                (int) $request->validated('warehouse_id'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'return' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "برگشت فروش {$return->invoice_number} تأیید و ثبت شد.",
        ]);

        return to_route('sales.returns.show', $return);
    }

    public function destroySales(Request $request, Invoice $return): RedirectResponse
    {
        abort_unless($request->user()?->canConfirmSales(), 403);
        $this->assertReturnInvoice($return, InvoiceType::SalesReturn);
        $this->deleteDraft($return, InvoiceType::SalesReturn);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'پیش‌نویس برگشت فروش حذف شد.',
        ]);

        return to_route('sales.show', $return->parent_invoice_id);
    }

    public function createPurchase(
        Request $request,
        Invoice $invoice,
        PurchaseReturnService $returns,
    ): Response {
        abort_unless($request->user()?->canManagePurchases(), 403);
        $this->assertOriginalInvoice($invoice, InvoiceType::Purchase);

        return $this->renderCreate(
            'purchase',
            $invoice,
            $returns->returnableItems($invoice),
        );
    }

    public function storePurchase(
        StorePurchaseReturnRequest $request,
        Invoice $invoice,
        PurchaseReturnService $returns,
    ): RedirectResponse {
        $this->assertOriginalInvoice($invoice, InvoiceType::Purchase);
        $data = $request->validated();

        try {
            $return = $returns->createDraft(
                $invoice,
                $data['items'],
                $data['return_date'],
                $request->user()->id,
                $data['notes'] ?? null,
                $data['submission_token'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'return' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس برگشت خرید {$return->invoice_number} ایجاد شد.",
        ]);

        return to_route('purchases.returns.show', $return);
    }

    public function showPurchase(Request $request, Invoice $return): Response
    {
        abort_unless($request->user()?->canManagePurchases(), 403);
        $this->assertReturnInvoice($return, InvoiceType::PurchaseReturn);

        return $this->renderShow('purchase', $return);
    }

    public function confirmPurchase(
        ConfirmPurchaseReturnRequest $request,
        Invoice $return,
        PurchaseReturnService $returns,
    ): RedirectResponse {
        $this->assertReturnInvoice($return, InvoiceType::PurchaseReturn);

        try {
            $returns->confirm(
                $return,
                (int) $request->validated('warehouse_id'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'return' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "برگشت خرید {$return->invoice_number} تأیید و ثبت شد.",
        ]);

        return to_route('purchases.returns.show', $return);
    }

    public function destroyPurchase(Request $request, Invoice $return): RedirectResponse
    {
        abort_unless($request->user()?->canManagePurchases(), 403);
        $this->assertReturnInvoice($return, InvoiceType::PurchaseReturn);
        $this->deleteDraft($return, InvoiceType::PurchaseReturn);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'پیش‌نویس برگشت خرید حذف شد.',
        ]);

        return to_route('purchases.show', $return->parent_invoice_id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function renderCreate(
        string $kind,
        Invoice $invoice,
        array $items,
    ): Response {
        $invoice->load($kind === 'sales'
            ? ['customer.party', 'fiscalYear']
            : ['supplier.party', 'fiscalYear']);
        $fiscalYear = $invoice->fiscalYear;
        $returnDate = max(
            $invoice->invoice_date->toDateString(),
            min($fiscalYear->ends_on->toDateString(), today()->toDateString()),
        );

        return Inertia::render('returns/Create', [
            'kind' => $kind,
            'original' => $this->mapInvoice($invoice, $kind),
            'items' => $items,
            'defaults' => [
                'return_date' => $returnDate,
                'submission_token' => (string) Str::uuid(),
            ],
            'fiscal_year' => [
                'title' => $fiscalYear->title,
                'starts_on' => $fiscalYear->starts_on->toDateString(),
                'ends_on' => $fiscalYear->ends_on->toDateString(),
            ],
        ]);
    }

    private function renderShow(string $kind, Invoice $return): Response
    {
        $return->load([
            'items',
            'parentInvoice',
            $kind === 'sales' ? 'customer.party' : 'supplier.party',
        ]);
        $warehouses = $return->status === InvoiceStatus::Draft
            ? $return->branch->warehouses()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default'])
            : [];

        return Inertia::render('returns/Show', [
            'kind' => $kind,
            'return_invoice' => [
                ...$this->mapInvoice($return, $kind),
                'notes' => $return->notes,
                'items' => $return->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                ])->values(),
            ],
            'parent' => $return->parentInvoice
                ? [
                    'id' => $return->parentInvoice->id,
                    'invoice_number' => $return->parentInvoice->invoice_number,
                    'invoice_date' => $return->parentInvoice->invoice_date->toDateString(),
                    'total' => $return->parentInvoice->total,
                    'status' => $return->parentInvoice->status->value,
                ]
                : null,
            'warehouses' => $warehouses,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapInvoice(Invoice $invoice, string $kind): array
    {
        $party = $kind === 'sales'
            ? $invoice->customer?->party
            : $invoice->supplier?->party;

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'status' => $invoice->status->value,
            'subtotal' => $invoice->subtotal,
            'discount_total' => $invoice->discount_total,
            'tax_total' => $invoice->tax_total,
            'total' => $invoice->total,
            'party' => [
                'name' => $party?->name,
                'code' => $party?->code,
                'phone' => $party?->phone,
            ],
        ];
    }

    private function assertOriginalInvoice(Invoice $invoice, InvoiceType $type): void
    {
        abort_unless(
            $invoice->type === $type
            && (int) $invoice->store_id === $this->mainStore()->id
            && (int) $invoice->branch_id === (int) $this->activeBranch()->id,
            404,
        );
    }

    private function assertReturnInvoice(Invoice $invoice, InvoiceType $type): void
    {
        abort_unless(
            $invoice->type === $type
            && (int) $invoice->store_id === $this->mainStore()->id
            && (int) $invoice->branch_id === (int) $this->activeBranch()->id,
            404,
        );
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function activeBranch(): Branch
    {
        return app(BranchContextService::class)
            ->active(request()->user(), $this->mainStore());
    }

    private function deleteDraft(Invoice $return, InvoiceType $type): void
    {
        DB::transaction(function () use ($return, $type): void {
            $snapshot = Invoice::query()->findOrFail($return->id);
            Invoice::query()
                ->whereKey($snapshot->parent_invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = Invoice::query()
                ->whereKey($return->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->type !== $type
                || (int) $locked->store_id !== $this->mainStore()->id
                || (int) $locked->branch_id !== (int) $this->activeBranch()->id
                || $locked->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages([
                    'return' => 'فقط پیش‌نویس برگشت قابل حذف است.',
                ]);
            }

            $locked->delete();
        });
    }
}
