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
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly SupplierPayableService $payables,
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
        $requestHash = hash('sha256', json_encode([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'supplier_id' => $supplierId,
            'payment_date' => $paymentDate,
            'payment_method' => $paymentMethod->value,
            'treasury_account_id' => $treasuryAccountId,
            'check_id' => $checkId,
            'reference' => $reference,
            'description' => $description,
            'created_by' => $approver->id,
            'allocations' => $canonicalAllocations,
        ], JSON_THROW_ON_ERROR));

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
            $canonicalAllocations,
        ): Payment {
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                if ($existing->idempotency_request_hash !== null
                    && ! hash_equals($existing->idempotency_request_hash, $requestHash)) {
                    throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
                }

                return $existing->load(['allocations.invoice', 'supplier.party', 'check']);
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

            if (! $supplier->is_active || ! $party?->is_active) {
                throw new DomainException('تأمین‌کننده پرداخت فعال نیست.');
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
                    || Decimal::compare($amount, $this->payables->outstandingForInvoice($invoice), Decimal::MONEY_SCALE) > 0) {
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
            } elseif ($checkId !== null) {
                throw new DomainException('برای روش‌های غیرچکی نباید شناسه چک ارسال شود.');
            } else {
                $treasuryType = $paymentMethod === PaymentMethod::Cash
                    ? TreasuryAccountType::Cash
                    : TreasuryAccountType::Bank;
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
    }
}
