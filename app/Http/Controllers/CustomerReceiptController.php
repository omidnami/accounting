<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Http\Requests\CustomerReceiptRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\CustomerReceivableService;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageReceipts(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $paymentMethod = $validated['payment_method'] ?? null;
        $base = Receipt::query()->where('store_id', $store->id);
        $receipts = (clone $base)
            ->with(['customer.party:id,code,name,phone', 'allocations'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('receipt_number', 'like', $term)
                        ->orWhere('reference', 'like', $term)
                        ->orWhereHas('customer.party', function (Builder $query) use ($term): void {
                            $query
                                ->where('name', 'like', $term)
                                ->orWhere('code', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->when($paymentMethod !== null, fn (Builder $query) => $query->where('payment_method', $paymentMethod))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Receipt $receipt): array => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->receipt_date->toDateString(),
                'amount' => $receipt->amount,
                'payment_method' => $receipt->payment_method->value,
                'status' => $receipt->status->value,
                'allocations_count' => $receipt->allocations->count(),
                'customer' => [
                    'name' => $receipt->customer->party->name,
                    'code' => $receipt->customer->party->code,
                    'phone' => $receipt->customer->party->phone,
                ],
            ]);

        return Inertia::render('receipts/Index', [
            'receipts' => $receipts,
            'filters' => [
                'search' => $search,
                'payment_method' => $paymentMethod,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'received' => (string) (clone $base)
                    ->where('status', TreasuryDocumentStatus::Confirmed)
                    ->sum('amount'),
            ],
        ]);
    }

    public function create(Request $request, CustomerReceivableService $receivables): Response
    {
        abort_unless($request->user()?->canManageReceipts(), 403);

        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $receiptDate = max(
            $fiscalYear->starts_on->toDateString(),
            min($fiscalYear->ends_on->toDateString(), now()->toImmutable()->toDateString()),
        );
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ]);
        $initialCustomer = null;
        $initialInvoices = [];
        $focusInvoiceId = null;
        $customerId = isset($validated['customer_id']) ? (int) $validated['customer_id'] : null;

        if ($customerId !== null) {
            $customer = Customer::query()
                ->with('party')
                ->whereKey($customerId)
                ->where('is_active', true)
                ->whereHas('party', fn (Builder $query) => $query
                    ->where('store_id', $store->id)
                    ->where('is_active', true))
                ->firstOrFail();
            $focusInvoiceId = isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null;
            $initialInvoices = $this->mapOpenInvoices(
                $receivables->openInvoices($customer, $store->id, $focusInvoiceId),
                $receivables,
            );
            $initialCustomer = $this->mapCustomer($customer);
        }

        return Inertia::render('receipts/Create', [
            'defaults' => [
                'receipt_date' => $receiptDate,
                'submission_token' => (string) Str::uuid(),
            ],
            'fiscal_year' => [
                'title' => $fiscalYear->title,
                'starts_on' => $fiscalYear->starts_on->toDateString(),
                'ends_on' => $fiscalYear->ends_on->toDateString(),
            ],
            'initial_customer' => $initialCustomer,
            'initial_invoices' => $initialInvoices,
            'initial_invoice_id' => $focusInvoiceId,
            'treasury_accounts' => $this->treasuryAccounts($store, $branch),
        ]);
    }

    public function openInvoices(
        Request $request,
        CustomerReceivableService $receivables,
    ): JsonResponse {
        abort_unless($request->user()?->canManageReceipts(), 403);

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ]);
        $store = $this->mainStore();
        $customer = Customer::query()
            ->with('party')
            ->whereKey((int) $validated['customer_id'])
            ->where('is_active', true)
            ->whereHas('party', fn (Builder $query) => $query
                ->where('store_id', $store->id)
                ->where('is_active', true))
            ->firstOrFail();
        $focusInvoiceId = isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null;

        return response()->json([
            'customer' => $this->mapCustomer($customer),
            'invoices' => $this->mapOpenInvoices(
                $receivables->openInvoices($customer, $store->id, $focusInvoiceId),
                $receivables,
            ),
        ]);
    }

    public function store(
        CustomerReceiptRequest $request,
        CustomerReceiptService $receipts,
    ): RedirectResponse {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $data = $request->validated();

        try {
            $receipt = $receipts->receive(
                storeId: $store->id,
                branchId: $branch->id,
                fiscalYearId: $fiscalYear->id,
                customerId: (int) $data['customer_id'],
                receiptDate: $data['receipt_date'],
                paymentMethod: PaymentMethod::from($data['payment_method']),
                allocations: $data['allocations'],
                approver: $request->user(),
                idempotencyKey: $data['submission_token'],
                treasuryAccountId: isset($data['treasury_account_id'])
                    ? (int) $data['treasury_account_id']
                    : null,
                reference: $data['reference'] ?? null,
                description: $data['description'] ?? null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'receipt' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "دریافت {$receipt->receipt_number} ثبت شد.",
        ]);

        return to_route('receipts.show', $receipt);
    }

    public function show(Request $request, Receipt $receipt): Response
    {
        abort_unless($request->user()?->canManageReceipts(), 403);
        abort_unless($receipt->store_id === $this->mainStore()->id, 404);
        $receipt->load([
            'customer.party',
            'allocations.invoice',
            'treasuryAccount',
        ]);

        return Inertia::render('receipts/Show', [
            'receipt' => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->receipt_date->toDateString(),
                'amount' => $receipt->amount,
                'payment_method' => $receipt->payment_method->value,
                'status' => $receipt->status->value,
                'reference' => $receipt->reference,
                'description' => $receipt->description,
                'treasury_account' => $receipt->treasuryAccount?->name,
                'customer' => [
                    'id' => $receipt->customer->id,
                    'name' => $receipt->customer->party->name,
                    'code' => $receipt->customer->party->code,
                    'phone' => $receipt->customer->party->phone,
                ],
                'allocations' => $receipt->allocations->map(fn ($allocation): array => [
                    'id' => $allocation->id,
                    'amount' => $allocation->amount,
                    'invoice' => [
                        'id' => $allocation->invoice->id,
                        'invoice_number' => $allocation->invoice->invoice_number,
                        'invoice_date' => $allocation->invoice->invoice_date->toDateString(),
                        'status' => $allocation->invoice->status->value,
                        'total' => $allocation->invoice->total,
                    ],
                ])->values(),
            ],
        ]);
    }

    /**
     * @return array{
     *     id: int, code: string, name: string, phone: string|null,
     *     credit_limit: string
     * }
     */
    private function mapCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->party->code,
            'name' => $customer->party->name,
            'phone' => $customer->party->phone,
            'credit_limit' => $customer->credit_limit,
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, array<string, mixed>>
     */
    private function mapOpenInvoices(
        Collection $invoices,
        CustomerReceivableService $receivables,
    ): array {
        return $invoices->map(fn (Invoice $invoice): array => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status->value,
            'total' => $invoice->total,
            'outstanding' => $receivables->outstandingForInvoice($invoice),
        ])->all();
    }

    /**
     * @return array<int, array{id: int, name: string, type: string, is_default: bool}>
     */
    private function treasuryAccounts(Store $store, Branch $branch): array
    {
        return TreasuryAccount::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereIn('type', [
                TreasuryAccountType::Cash,
                TreasuryAccountType::Bank,
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default'])
            ->map(fn (TreasuryAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'is_default' => $account->is_default,
            ])
            ->all();
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
