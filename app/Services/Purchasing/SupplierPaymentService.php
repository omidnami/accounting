<?php

namespace App\Services\Purchasing;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Check;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Parties\PartySnapshotService;
use App\Support\Decimal;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly SupplierPayableService $payables,
        private readonly PartySnapshotService $partySnapshots,
    ) {}

    /**
     * @param  array<int, array{invoice_id: int, amount: string|int|float}>  $allocations
     */
    public function pay(
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        int $supplierId,
        string $paymentDate,
        PaymentMethod $paymentMethod,
        array $allocations,
        User $approver,
        string $idempotencyKey,
        ?int $treasuryAccountId = null,
        ?int $checkId = null,
        ?string $reference = null,
        ?string $description = null,
    ): Payment {
        if ($allocations === []) {
            throw new DomainException('پرداخت باید حداقل به یک فاکتور تخصیص یابد.');
        }

        $canonicalAllocations = collect($allocations)
            ->map(fn (array $allocation): array => [
                'invoice_id' => (int) $allocation['invoice_id'],
                'amount' => Decimal::normalize($allocation['amount'], 2),
            ])
            ->sortBy('invoice_id')
            ->values()
            ->all();
        $requestedAmount = collect($canonicalAllocations)
            ->reduce(
                fn (string $total, array $allocation): string => Decimal::add(
                    $total,
                    $allocation['amount'],
                    Decimal::MONEY_SCALE,
                ),
                '0.00',
            );
        $requestPayload = $this->requestPayload(
            $storeId,
            $branchId,
            $fiscalYearId,
            $supplierId,
            $paymentDate,
            $paymentMethod,
            $treasuryAccountId,
            $checkId,
            $reference,
            $description,
            $approver->id,
            $canonicalAllocations,
            $requestedAmount,
        );
        $requestHash = IdempotencyHasher::hash('supplier.payment', $requestPayload);

        try {
            return DB::transaction(function () use (
                $storeId,
                $branchId,
                $fiscalYearId,
                $supplierId,
                $paymentDate,
                $paymentMethod,
                $allocations,
                $approver,
                $idempotencyKey,
                $treasuryAccountId,
                $checkId,
                $reference,
                $description,
                $requestHash,
                $requestPayload,
                $canonicalAllocations,
            ): Payment {
                $existing = Payment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentPayment($existing, $requestHash, $requestPayload);
                }

                $supplier = Supplier::query()
                    ->whereKey($supplierId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $party = Party::query()
                    ->whereKey($supplier->party_id)
                    ->lockForUpdate()
                    ->first();
                $supplier->setRelation('party', $party);

                if (! $party) {
                    throw new DomainException('تأمین‌کننده پرداخت معتبر نیست.');
                }

                if ((int) $party->store_id !== $storeId) {
                    throw new DomainException('تأمین‌کننده باید متعلق به فروشگاه پرداخت باشد.');
                }

                $branch = Branch::query()->findOrFail($branchId);
                $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);

                if ((int) $branch->store_id !== $storeId || (int) $fiscalYear->store_id !== $storeId) {
                    throw new DomainException('شعبه و سال مالی باید متعلق به فروشگاه پرداخت باشند.');
                }

                $period = AccountingPeriod::query()
                    ->where('store_id', $storeId)
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->whereDate('starts_on', '<=', $paymentDate)
                    ->whereDate('ends_on', '>=', $paymentDate)
                    ->first();

                if (! $period) {
                    throw new DomainException('برای تاریخ پرداخت دوره مالی پیدا نشد.');
                }

                $invoiceIds = collect($allocations)->pluck('invoice_id')->map(fn ($id): int => (int) $id);

                if ($invoiceIds->duplicates()->isNotEmpty()) {
                    throw new DomainException('یک فاکتور نمی‌تواند چند تخصیص در یک پرداخت داشته باشد.');
                }

                $invoices = Invoice::query()
                    ->whereIn('id', $invoiceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $paymentAmount = '0.00';

                foreach ($allocations as $allocation) {
                    $invoice = $invoices->get((int) $allocation['invoice_id']);

                    if (! $invoice
                        || $invoice->type !== InvoiceType::Purchase
                        || (int) $invoice->supplier_id !== $supplier->id
                        || (int) $invoice->branch_id !== $branchId
                        || ! in_array($invoice->status, [
                            InvoiceStatus::Confirmed,
                            InvoiceStatus::PartiallyPaid,
                        ], true)) {
                        throw new DomainException('فاکتور انتخاب‌شده برای پرداخت معتبر نیست.');
                    }

                    $amount = Decimal::normalize($allocation['amount'], Decimal::MONEY_SCALE);

                    if (! Decimal::isPositive($amount)
                        || Decimal::compare(
                            $amount,
                            $this->payables->outstandingForInvoice($invoice, true),
                            Decimal::MONEY_SCALE,
                        ) > 0) {
                        throw new DomainException('مبلغ تخصیص از مانده فاکتور بیشتر است.');
                    }

                    $paymentAmount = Decimal::add($paymentAmount, $amount, Decimal::MONEY_SCALE);
                }

                $check = null;
                $treasuryAccount = null;

                if ($paymentMethod === PaymentMethod::Check) {
                    $check = Check::query()->lockForUpdate()->findOrFail($checkId);

                    if ($check->type !== CheckType::Payable
                        || $check->status !== CheckStatus::Pending
                        || $check->receipt()->exists()
                        || $check->payment()->exists()
                        || (int) $check->supplier_id !== $supplier->id
                        || (int) $check->store_id !== $storeId
                        || (int) $check->branch_id !== $branchId
                        || Decimal::compare($check->amount, $paymentAmount, Decimal::MONEY_SCALE) !== 0) {
                        throw new DomainException('چک پرداختی با تأمین‌کننده یا مبلغ پرداخت سازگار نیست.');
                    }

                    if ($check->party_snapshot === null) {
                        $check->forceFill([
                            'party_snapshot' => $this->partySnapshots->capture($party),
                        ])->save();
                    }
                } elseif ($checkId !== null) {
                    throw new DomainException('برای روش‌های غیرچکی نباید شناسه چک ارسال شود.');
                } else {
                    $treasuryType = match ($paymentMethod) {
                        PaymentMethod::Cash => TreasuryAccountType::Cash,
                        PaymentMethod::Bank => TreasuryAccountType::Bank,
                        PaymentMethod::Card => TreasuryAccountType::Card,
                    };
                    $treasuryAccount = TreasuryAccount::query()
                        ->where('store_id', $storeId)
                        ->where('branch_id', $branchId)
                        ->where('type', $treasuryType)
                        ->where('is_active', true)
                        ->when($treasuryAccountId !== null, fn ($query) => $query->whereKey($treasuryAccountId))
                        ->orderByDesc('is_default')
                        ->lockForUpdate()
                        ->first();

                    if (! $treasuryAccount || ! $treasuryAccount->glAccount()->where('is_active', true)->exists()) {
                        throw new DomainException('حساب خزانه فعال برای این روش پرداخت پیدا نشد.');
                    }
                }

                $payment = Payment::query()->create([
                    'store_id' => $storeId,
                    'branch_id' => $branchId,
                    'fiscal_year_id' => $fiscalYearId,
                    'payment_number' => $this->documentNumbers->next(
                        $storeId,
                        $branchId,
                        $fiscalYearId,
                        DocumentType::Payment,
                    ),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'party_snapshot' => $this->partySnapshots->capture($party),
                    'payment_date' => $paymentDate,
                    'amount' => $paymentAmount,
                    'payment_method' => $paymentMethod,
                    'treasury_account_id' => $treasuryAccount?->id,
                    'supplier_id' => $supplier->id,
                    'check_id' => $check?->id,
                    'created_by' => $approver->id,
                    'reference' => $reference,
                    'status' => TreasuryDocumentStatus::Pending,
                    'description' => $description,
                ]);

                foreach ($canonicalAllocations as $allocation) {
                    PaymentAllocation::query()->create([
                        'payment_id' => $payment->id,
                        'invoice_id' => $allocation['invoice_id'],
                        'amount' => Decimal::normalize($allocation['amount'], Decimal::MONEY_SCALE),
                    ]);
                }

                $this->posting->post(
                    [
                        'store_id' => $storeId,
                        'branch_id' => $branchId,
                        'fiscal_year_id' => $fiscalYearId,
                        'accounting_period_id' => $period->id,
                        'entry_date' => $paymentDate,
                        'description' => "پرداخت به تأمین‌کننده {$payment->payment_number}",
                        'source_type' => $payment->getMorphClass(),
                        'source_id' => $payment->id,
                        'created_by' => $approver->id,
                    ],
                    [
                        [
                            'account_id' => $this->settings->defaultAccountId('accounts_payable', $storeId),
                            'party_id' => $supplier->party_id,
                            'debit' => $paymentAmount,
                            'credit' => '0.00',
                            'description' => 'کاهش بدهی تأمین‌کننده',
                        ],
                        [
                            'account_id' => $treasuryAccount
                                ? $treasuryAccount->gl_account_id
                                : $this->settings->defaultAccountId('checks_payable', $storeId),
                            'debit' => '0.00',
                            'credit' => $paymentAmount,
                            'description' => 'منبع پرداخت',
                        ],
                    ],
                    $approver,
                );

                $payment->markConfirmed();

                foreach ($allocations as $allocation) {
                    $invoice = $invoices->get((int) $allocation['invoice_id']);
                    $invoice->syncSettlementStatus();
                }

                return $payment->refresh()->load(['allocations.invoice', 'supplier.party', 'check']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $existing = Payment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $this->resolveIdempotentPayment($existing, $requestHash, $requestPayload);
            }

            throw new DomainException('سند خزانه با این کلید تکرارپذیری قبلاً ثبت شده است.');
        }
    }

    /**
     * @param  array<int, array{invoice_id: int, amount: string}>  $allocations
     * @return array<string, mixed>
     */
    private function requestPayload(
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        int $supplierId,
        string $paymentDate,
        PaymentMethod $paymentMethod,
        ?int $treasuryAccountId,
        ?int $checkId,
        ?string $reference,
        ?string $description,
        int $createdBy,
        array $allocations,
        string $amount,
    ): array {
        $sourceInvoiceIds = collect($allocations)
            ->pluck('invoice_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'supplier_id' => $supplierId,
            'payment_date' => CarbonImmutable::parse($paymentDate)->toDateString(),
            'payment_method' => $paymentMethod->value,
            'amount' => $amount,
            'treasury_account_id' => $treasuryAccountId,
            'treasury_account_type' => $this->treasuryTypeFor($paymentMethod)?->value,
            'check_id' => $checkId,
            'reference' => $reference,
            'description' => $description,
            'created_by' => $createdBy,
            'allocations' => $allocations,
            'source_invoice_ids' => $sourceInvoiceIds,
        ];
    }

    /** @return array<string, mixed> */
    private function persistedPayload(Payment $payment): array
    {
        $allocations = $payment->allocations()
            ->orderBy('invoice_id')
            ->get()
            ->map(fn (PaymentAllocation $allocation): array => [
                'invoice_id' => (int) $allocation->invoice_id,
                'amount' => Decimal::normalize($allocation->amount, Decimal::MONEY_SCALE),
            ])
            ->values()
            ->all();
        $sourceInvoiceIds = collect($allocations)
            ->pluck('invoice_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'store_id' => (int) $payment->store_id,
            'branch_id' => (int) $payment->branch_id,
            'fiscal_year_id' => (int) $payment->fiscal_year_id,
            'supplier_id' => (int) $payment->supplier_id,
            'payment_date' => $payment->payment_date->toDateString(),
            'payment_method' => $payment->payment_method->value,
            'amount' => Decimal::normalize($payment->amount, Decimal::MONEY_SCALE),
            'treasury_account_id' => $payment->treasury_account_id === null ? null : (int) $payment->treasury_account_id,
            'treasury_account_type' => $payment->treasuryAccount?->type?->value,
            'check_id' => $payment->check_id === null ? null : (int) $payment->check_id,
            'reference' => $payment->reference,
            'description' => $payment->description,
            'created_by' => $payment->created_by === null ? null : (int) $payment->created_by,
            'allocations' => $allocations,
            'source_invoice_ids' => $sourceInvoiceIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    private function resolveIdempotentPayment(
        Payment $payment,
        string $requestHash,
        array $requestPayload,
    ): Payment {
        if ($payment->idempotency_request_hash !== null) {
            if (! hash_equals($payment->idempotency_request_hash, $requestHash)) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        } else {
            $persistedPayload = $this->persistedPayload($payment);

            if ($requestPayload['treasury_account_id'] === null) {
                $persistedPayload['treasury_account_id'] = null;
            }

            if (! hash_equals(
                IdempotencyHasher::hash('supplier.payment', $persistedPayload),
                $requestHash,
            )) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        }

        return $payment->load(['allocations.invoice', 'supplier.party', 'check']);
    }

    private function treasuryTypeFor(PaymentMethod $paymentMethod): ?TreasuryAccountType
    {
        return match ($paymentMethod) {
            PaymentMethod::Cash => TreasuryAccountType::Cash,
            PaymentMethod::Bank => TreasuryAccountType::Bank,
            PaymentMethod::Card => TreasuryAccountType::Card,
            PaymentMethod::Check => null,
        };
    }
}
