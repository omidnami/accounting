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
use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerReceiptService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
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
    ): Receipt {
        if ($allocations === []) {
            throw new DomainException('دریافت باید حداقل به یک فاکتور تخصیص یابد.');
        }

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
        ): Receipt {
            $existing = Receipt::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing->load(['allocations.invoice', 'customer.party', 'check']);
            }

            $customer = Customer::query()->with('party')->findOrFail($customerId);

            if (! $customer->is_active || ! $customer->party?->is_active) {
                throw new DomainException('مشتری دریافت فعال نیست.');
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
                ->where('starts_on', '<=', $receiptDate)
                ->where('ends_on', '>=', $receiptDate)
                ->first();

            if (! $period) {
                throw new DomainException('برای تاریخ دریافت دوره مالی پیدا نشد.');
            }

            $invoiceIds = collect($allocations)->pluck('invoice_id')->map(fn ($id): int => (int) $id);

            if ($invoiceIds->duplicates()->isNotEmpty()) {
                throw new DomainException('یک فاکتور نمی‌تواند چند تخصیص در یک دریافت داشته باشد.');
            }

            $invoices = Invoice::query()->whereIn('id', $invoiceIds)->lockForUpdate()->get()->keyBy('id');
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
                    || Decimal::compare($amount, $this->outstandingFor($invoice), 2) > 0) {
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
                    || (int) $check->customer_id !== $customer->id
                    || (int) $check->store_id !== $storeId
                    || Decimal::compare($check->amount, $amountTotal, 2) !== 0) {
                    throw new DomainException('چک دریافتی با مشتری یا مبلغ دریافت سازگار نیست.');
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

            foreach ($allocations as $allocation) {
                ReceiptAllocation::query()->create([
                    'receipt_id' => $receipt->id,
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => Decimal::normalize($allocation['amount'], 2),
                ]);
            }

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
            );

            $receipt->forceFill(['status' => TreasuryDocumentStatus::Confirmed])->save();

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get((int) $allocation['invoice_id']);
                $outstanding = $this->outstandingFor($invoice);
                $invoice->forceFill([
                    'status' => Decimal::isZero($outstanding)
                        ? InvoiceStatus::Paid
                        : InvoiceStatus::PartiallyPaid,
                ])->save();
            }

            return $receipt->refresh()->load(['allocations.invoice', 'customer.party', 'check']);
        });
    }

    private function outstandingFor(Invoice $invoice): string
    {
        $received = (string) $invoice->receiptAllocations()
            ->whereHas('receipt', function ($query): void {
                $query->where('status', TreasuryDocumentStatus::Confirmed);
            })
            ->sum('amount');
        $returned = (string) Invoice::query()
            ->where('parent_invoice_id', $invoice->id)
            ->where('type', InvoiceType::SalesReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->sum('total');
        $outstanding = Decimal::subtract($invoice->total, $received, 2);
        $outstanding = Decimal::subtract($outstanding, $returned, 2);

        return Decimal::compare($outstanding, '0', 2) > 0 ? $outstanding : '0.00';
    }
}
