<?php

namespace App\Services\Sales;

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
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\PosTender;
use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\Contracts\PostingAuthorizationContext;
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

class CustomerReceiptService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly CustomerReceivableService $receivables,
        private readonly PartySnapshotService $partySnapshots,
    ) {}

    /**
     * @param  array<int, array{invoice_id: int, amount: string|int|float}>  $allocations
     */
    public function receive(
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        int $customerId,
        string $receiptDate,
        PaymentMethod $paymentMethod,
        array $allocations,
        User $approver,
        string $idempotencyKey,
        ?int $treasuryAccountId = null,
        ?int $checkId = null,
        ?string $reference = null,
        ?string $description = null,
        ?PostingAuthorizationContext $posContext = null,
        ?int $posTenderId = null,
    ): Receipt {
        if ($allocations === []) {
            throw new DomainException('دریافت باید حداقل به یک فاکتور تخصیص یابد.');
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
            $customerId,
            $receiptDate,
            $paymentMethod,
            $treasuryAccountId,
            $checkId,
            $reference,
            $description,
            $approver->id,
            $canonicalAllocations,
            $requestedAmount,
            $posTenderId,
        );
        $requestHash = IdempotencyHasher::hash('customer.receipt', $requestPayload);

        try {
            return DB::transaction(function () use (
                $storeId,
                $branchId,
                $fiscalYearId,
                $customerId,
                $receiptDate,
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
                $posContext,
                $posTenderId,
            ): Receipt {
                $existing = Receipt::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentReceipt($existing, $requestHash, $requestPayload);
                }

                $customer = Customer::query()->with('party')->findOrFail($customerId);

                if (! $customer->party) {
                    throw new DomainException('مشتری دریافت معتبر نیست.');
                }

                if ((int) $customer->party->store_id !== $storeId) {
                    throw new DomainException('مشتری باید متعلق به فروشگاه دریافت باشد.');
                }

                $branch = Branch::query()->findOrFail($branchId);
                $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);

                if ((int) $branch->store_id !== $storeId || (int) $fiscalYear->store_id !== $storeId) {
                    throw new DomainException('شعبه و سال مالی باید متعلق به فروشگاه دریافت باشند.');
                }

                $period = AccountingPeriod::query()
                    ->where('store_id', $storeId)
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->whereDate('starts_on', '<=', $receiptDate)
                    ->whereDate('ends_on', '>=', $receiptDate)
                    ->first();

                if (! $period) {
                    throw new DomainException('برای تاریخ دریافت دوره مالی پیدا نشد.');
                }

                $invoiceIds = collect($allocations)->pluck('invoice_id')->map(fn ($id): int => (int) $id);

                if ($invoiceIds->duplicates()->isNotEmpty()) {
                    throw new DomainException('یک فاکتور نمی‌تواند چند تخصیص در یک دریافت داشته باشد.');
                }

                $invoices = Invoice::query()
                    ->whereIn('id', $invoiceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $amountTotal = '0.00';

                foreach ($allocations as $allocation) {
                    $invoice = $invoices->get((int) $allocation['invoice_id']);

                    if (! $invoice
                        || $invoice->type !== InvoiceType::Sales
                        || (int) $invoice->customer_id !== $customer->id
                        || (int) $invoice->branch_id !== $branchId
                        || ! in_array($invoice->status, [
                            InvoiceStatus::Confirmed,
                            InvoiceStatus::PartiallyPaid,
                        ], true)) {
                        throw new DomainException('فاکتور فروش انتخاب‌شده معتبر نیست.');
                    }

                    $amount = Decimal::normalize($allocation['amount'], 2);

                    if (! Decimal::isPositive($amount)
                        || Decimal::compare(
                            $amount,
                            $this->receivables->outstandingForInvoice($invoice, true),
                            2,
                        ) > 0) {
                        throw new DomainException('مبلغ دریافت از مانده فاکتور بیشتر است.');
                    }

                    $amountTotal = Decimal::add($amountTotal, $amount, 2);
                }

                $check = null;
                $treasuryAccount = null;

                if ($paymentMethod === PaymentMethod::Check) {
                    $check = Check::query()->lockForUpdate()->findOrFail($checkId);

                    if ($check->type !== CheckType::Receivable
                        || $check->status !== CheckStatus::Pending
                        || $check->receipt()->exists()
                        || $check->payment()->exists()
                        || (int) $check->customer_id !== $customer->id
                        || (int) $check->store_id !== $storeId
                        || (int) $check->branch_id !== $branchId
                        || Decimal::compare($check->amount, $amountTotal, 2) !== 0) {
                        throw new DomainException('چک دریافتی با مشتری یا مبلغ دریافت سازگار نیست.');
                    }

                    if ($check->party_snapshot === null) {
                        $check->forceFill([
                            'party_snapshot' => $this->partySnapshots->capture($customer->party),
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
                        throw new DomainException('حساب خزانه فعال برای این روش دریافت پیدا نشد.');
                    }
                }

                $receipt = Receipt::query()->create([
                    'store_id' => $storeId,
                    'branch_id' => $branchId,
                    'fiscal_year_id' => $fiscalYearId,
                    'receipt_number' => $this->documentNumbers->next(
                        $storeId,
                        $branchId,
                        $fiscalYearId,
                        DocumentType::Receipt,
                    ),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'party_snapshot' => $this->partySnapshots->capture($customer->party),
                    'receipt_date' => $receiptDate,
                    'amount' => $amountTotal,
                    'payment_method' => $paymentMethod,
                    'treasury_account_id' => $treasuryAccount?->id,
                    'customer_id' => $customer->id,
                    'check_id' => $check?->id,
                    'created_by' => $approver->id,
                    'reference' => $reference,
                    'status' => TreasuryDocumentStatus::Pending,
                    'description' => $description,
                ]);

                foreach ($canonicalAllocations as $allocation) {
                    ReceiptAllocation::query()->create([
                        'receipt_id' => $receipt->id,
                        'invoice_id' => $allocation['invoice_id'],
                        'amount' => Decimal::normalize($allocation['amount'], 2),
                    ]);
                }

                $posContext?->prepareTreasuryDocument($receipt, $approver, $posTenderId);

                $this->posting->post(
                    [
                        'store_id' => $storeId,
                        'branch_id' => $branchId,
                        'fiscal_year_id' => $fiscalYearId,
                        'accounting_period_id' => $period->id,
                        'entry_date' => $receiptDate,
                        'description' => "دریافت از مشتری {$receipt->receipt_number}",
                        'source_type' => $receipt->getMorphClass(),
                        'source_id' => $receipt->id,
                        'created_by' => $approver->id,
                    ],
                    [
                        [
                            'account_id' => $treasuryAccount
                                ? $treasuryAccount->gl_account_id
                                : $this->settings->defaultAccountId('checks_receivable', $storeId),
                            'debit' => $amountTotal,
                            'credit' => '0.00',
                            'description' => 'منبع دریافت',
                        ],
                        [
                            'account_id' => $this->settings->defaultAccountId('accounts_receivable', $storeId),
                            'party_id' => $customer->party_id,
                            'debit' => '0.00',
                            'credit' => $amountTotal,
                            'description' => 'کاهش مطالبات مشتری',
                        ],
                    ],
                    $approver,
                    $posContext,
                );

                $receipt->markConfirmed();

                foreach ($allocations as $allocation) {
                    $invoice = $invoices->get((int) $allocation['invoice_id']);
                    $invoice->syncSettlementStatus();
                }

                return $receipt->refresh()->load(['allocations.invoice', 'customer.party', 'check']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $existing = Receipt::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $this->resolveIdempotentReceipt($existing, $requestHash, $requestPayload);
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
        int $customerId,
        string $receiptDate,
        PaymentMethod $paymentMethod,
        ?int $treasuryAccountId,
        ?int $checkId,
        ?string $reference,
        ?string $description,
        int $createdBy,
        array $allocations,
        string $amount,
        ?int $posTenderId,
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
            'customer_id' => $customerId,
            'receipt_date' => CarbonImmutable::parse($receiptDate)->toDateString(),
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
            'pos_tender_id' => $posTenderId,
        ];
    }

    /** @return array<string, mixed> */
    private function persistedPayload(Receipt $receipt): array
    {
        $allocations = $receipt->allocations()
            ->orderBy('invoice_id')
            ->get()
            ->map(fn (ReceiptAllocation $allocation): array => [
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
        $posTenderId = PosTender::query()
            ->where('receipt_id', $receipt->id)
            ->value('id');

        return [
            'store_id' => (int) $receipt->store_id,
            'branch_id' => (int) $receipt->branch_id,
            'fiscal_year_id' => (int) $receipt->fiscal_year_id,
            'customer_id' => (int) $receipt->customer_id,
            'receipt_date' => $receipt->receipt_date->toDateString(),
            'payment_method' => $receipt->payment_method->value,
            'amount' => Decimal::normalize($receipt->amount, Decimal::MONEY_SCALE),
            'treasury_account_id' => $receipt->treasury_account_id === null ? null : (int) $receipt->treasury_account_id,
            'treasury_account_type' => $receipt->treasuryAccount?->type?->value,
            'check_id' => $receipt->check_id === null ? null : (int) $receipt->check_id,
            'reference' => $receipt->reference,
            'description' => $receipt->description,
            'created_by' => $receipt->created_by === null ? null : (int) $receipt->created_by,
            'allocations' => $allocations,
            'source_invoice_ids' => $sourceInvoiceIds,
            'pos_tender_id' => $posTenderId === null ? null : (int) $posTenderId,
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    private function resolveIdempotentReceipt(
        Receipt $receipt,
        string $requestHash,
        array $requestPayload,
    ): Receipt {
        if ($receipt->idempotency_request_hash !== null) {
            if (! hash_equals($receipt->idempotency_request_hash, $requestHash)) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        } else {
            $persistedPayload = $this->persistedPayload($receipt);

            if ($requestPayload['treasury_account_id'] === null) {
                $persistedPayload['treasury_account_id'] = null;
            }

            if ($requestPayload['pos_tender_id'] === null) {
                $persistedPayload['pos_tender_id'] = null;
            }

            if (! hash_equals(
                IdempotencyHasher::hash('customer.receipt', $persistedPayload),
                $requestHash,
            )) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        }

        return $receipt->load(['allocations.invoice', 'customer.party', 'check']);
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
