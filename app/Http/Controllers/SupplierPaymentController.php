<?php

namespace App\Http\Controllers;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Http\Requests\SupplierPaymentRequest;
use App\Models\Branch;
use App\Models\Check;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Services\Purchasing\SupplierPayableService;
use App\Services\Purchasing\SupplierPaymentService;
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

class SupplierPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManagePayments(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $paymentMethod = $validated['payment_method'] ?? null;
        $base = Payment::query()->where('store_id', $store->id);
        $payments = (clone $base)
            ->with(['supplier.party:id,code,name,phone', 'allocations'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('payment_number', 'like', $term)
                        ->orWhere('reference', 'like', $term)
                        ->orWhereHas('supplier.party', function (Builder $query) use ($term): void {
                            $query
                                ->where('name', 'like', $term)
                                ->orWhere('code', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->when($paymentMethod !== null, fn (Builder $query) => $query->where('payment_method', $paymentMethod))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Payment $payment): array => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method->value,
                'status' => $payment->status->value,
                'allocations_count' => $payment->allocations->count(),
                'supplier' => [
                    'name' => $payment->supplier->party->name,
                    'code' => $payment->supplier->party->code,
                    'phone' => $payment->supplier->party->phone,
                ],
            ]);

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'filters' => [
                'search' => $search,
                'payment_method' => $paymentMethod,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'paid' => (string) (clone $base)
                    ->where('status', TreasuryDocumentStatus::Confirmed)
                    ->sum('amount'),
            ],
        ]);
    }

    public function create(Request $request, SupplierPayableService $payables): Response
    {
        abort_unless($request->user()?->canManagePayments(), 403);

        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $paymentDate = max(
            $fiscalYear->starts_on->toDateString(),
            min($fiscalYear->ends_on->toDateString(), now()->toImmutable()->toDateString()),
        );
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'invoice_ids' => [
                'nullable',
                'string',
                'max:1000',
                'regex:/^\d+(,\d+)*$/',
            ],
            'check_id' => ['nullable', 'integer', 'exists:checks,id'],
        ]);
        $initialSupplier = null;
        $initialInvoices = [];
        $initialChecks = [];
        $initialInvoiceIds = [];
        $focusInvoiceId = null;
        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;

        if ($supplierId !== null) {
            $supplier = Supplier::query()
                ->with('party')
                ->whereKey($supplierId)
                ->where('is_active', true)
                ->whereHas('party', fn (Builder $query) => $query
                    ->where('store_id', $store->id)
                    ->where('is_active', true))
                ->firstOrFail();
            $focusInvoiceId = isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null;
            $initialInvoiceIds = array_values(array_unique(array_map(
                fn (string $id): int => (int) $id,
                array_filter(explode(',', (string) ($validated['invoice_ids'] ?? ''))),
            )));

            if ($focusInvoiceId !== null && ! in_array($focusInvoiceId, $initialInvoiceIds, true)) {
                array_unshift($initialInvoiceIds, $focusInvoiceId);
            }

            $initialInvoices = $this->mapOpenInvoices(
                $payables->openInvoices($supplier, $store->id, $focusInvoiceId),
                $payables,
            );
            $initialChecks = $this->pendingChecks($supplier, $store->id, $branch->id);
            $initialCheckId = isset($validated['check_id'])
                && collect($initialChecks)->contains('id', (int) $validated['check_id'])
                ? (int) $validated['check_id']
                : null;
            $initialSupplier = $this->mapSupplier($supplier);
        }

        return Inertia::render('payments/Create', [
            'defaults' => [
                'payment_date' => $paymentDate,
                'submission_token' => (string) Str::uuid(),
            ],
            'fiscal_year' => [
                'title' => $fiscalYear->title,
                'starts_on' => $fiscalYear->starts_on->toDateString(),
                'ends_on' => $fiscalYear->ends_on->toDateString(),
            ],
            'initial_supplier' => $initialSupplier,
            'initial_invoices' => $initialInvoices,
            'initial_checks' => $initialChecks,
            'initial_invoice_id' => $focusInvoiceId,
            'initial_invoice_ids' => $initialInvoiceIds,
            'initial_check_id' => $initialCheckId ?? null,
            'treasury_accounts' => $this->treasuryAccounts($store, $branch),
        ]);
    }

    public function openInvoices(
        Request $request,
        SupplierPayableService $payables,
    ): JsonResponse {
        abort_unless($request->user()?->canManagePayments(), 403);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ]);
        $store = $this->mainStore();
        $supplier = Supplier::query()
            ->with('party')
            ->whereKey((int) $validated['supplier_id'])
            ->where('is_active', true)
            ->whereHas('party', fn (Builder $query) => $query
                ->where('store_id', $store->id)
                ->where('is_active', true))
            ->firstOrFail();
        $focusInvoiceId = isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null;

        return response()->json([
            'supplier' => $this->mapSupplier($supplier),
            'invoices' => $this->mapOpenInvoices(
                $payables->openInvoices($supplier, $store->id, $focusInvoiceId),
                $payables,
            ),
            'checks' => $this->pendingChecks(
                $supplier,
                $store->id,
                $this->defaultBranch($store)->id,
            ),
        ]);
    }

    public function store(
        SupplierPaymentRequest $request,
        SupplierPaymentService $payments,
    ): RedirectResponse {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $data = $request->validated();

        try {
            $payment = $payments->pay(
                storeId: $store->id,
                branchId: $branch->id,
                fiscalYearId: $fiscalYear->id,
                supplierId: (int) $data['supplier_id'],
                paymentDate: $data['payment_date'],
                paymentMethod: PaymentMethod::from($data['payment_method']),
                allocations: $data['allocations'],
                approver: $request->user(),
                idempotencyKey: $data['submission_token'],
                treasuryAccountId: isset($data['treasury_account_id'])
                    ? (int) $data['treasury_account_id']
                    : null,
                checkId: isset($data['check_id'])
                    ? (int) $data['check_id']
                    : null,
                reference: $data['reference'] ?? null,
                description: $data['description'] ?? null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'payment' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پرداخت {$payment->payment_number} ثبت شد.",
        ]);

        return to_route('payments.show', $payment);
    }

    public function show(Request $request, Payment $payment): Response
    {
        abort_unless($request->user()?->canManagePayments(), 403);
        abort_unless($payment->store_id === $this->mainStore()->id, 404);
        $payment->load([
            'supplier.party',
            'allocations.invoice',
            'treasuryAccount',
            'check',
        ]);

        return Inertia::render('payments/Show', [
            'payment' => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method->value,
                'status' => $payment->status->value,
                'reference' => $payment->reference,
                'description' => $payment->description,
                'treasury_account' => $payment->treasuryAccount?->name,
                'check' => $payment->check
                    ? [
                        'id' => $payment->check->id,
                        'document_number' => $payment->check->document_number,
                        'check_number' => $payment->check->check_number,
                        'status' => $payment->check->status->value,
                    ]
                    : null,
                'supplier' => [
                    'id' => $payment->supplier->id,
                    'name' => $payment->supplier->party->name,
                    'code' => $payment->supplier->party->code,
                    'phone' => $payment->supplier->party->phone,
                ],
                'allocations' => $payment->allocations->map(fn ($allocation): array => [
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
     *     payment_terms: string|null
     * }
     */
    private function mapSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'code' => $supplier->party->code,
            'name' => $supplier->party->name,
            'phone' => $supplier->party->phone,
            'payment_terms' => $supplier->payment_terms,
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, array<string, mixed>>
     */
    private function mapOpenInvoices(
        Collection $invoices,
        SupplierPayableService $payables,
    ): array {
        return $invoices->map(fn (Invoice $invoice): array => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status->value,
            'total' => $invoice->total,
            'outstanding' => $payables->outstandingForInvoice($invoice),
        ])->all();
    }

    /**
     * @return array<int, array{
     *     id: int, document_number: string|null, check_number: string, bank_name: string,
     *     due_date: string, amount: string
     * }>
     */
    private function pendingChecks(Supplier $supplier, int $storeId, int $branchId): array
    {
        return Check::query()
            ->where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplier->id)
            ->where('type', CheckType::Payable)
            ->where('status', CheckStatus::Pending)
            ->whereDoesntHave('receipt')
            ->whereDoesntHave('payment')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'document_number', 'check_number', 'bank_name', 'due_date', 'amount'])
            ->map(fn (Check $check): array => [
                'id' => $check->id,
                'document_number' => $check->document_number,
                'check_number' => $check->check_number,
                'bank_name' => $check->bank_name,
                'due_date' => $check->due_date->toDateString(),
                'amount' => $check->amount,
            ])
            ->all();
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
        return $store->defaultBranch()->where('is_active', true)->firstOrFail();
    }

    private function currentFiscalYear(Store $store): FiscalYear
    {
        return $store->fiscalYears()->where('is_current', true)->firstOrFail();
    }
}
