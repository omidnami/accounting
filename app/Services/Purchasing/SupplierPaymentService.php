<?php

namespace App\Services\Purchasing;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryDocumentStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Check;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
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
        ?int $checkId = null,
        ?string $reference = null,
        ?string $description = null,
    ): Payment {
        if ($allocations === []) {
            throw new DomainException('پرداخت باید حداقل به یک فاکتور تخصیص یابد.');
        }

        return DB::transaction(function () use (
            $storeId,
            $branchId,
            $fiscalYearId,
            $supplierId,
            $paymentDate,
            $paymentMethod,
            $allocations,
            $approver,
            $checkId,
            $reference,
            $description,
        ): Payment {
            $supplier = Supplier::query()
                ->with('party')
                ->findOrFail($supplierId);

            if (! $supplier->is_active || ! $supplier->party?->is_active) {
                throw new DomainException('تأمین‌کننده پرداخت فعال نیست.');
            }

            if ((int) $supplier->party->store_id !== $storeId) {
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
                ->where('starts_on', '<=', $paymentDate)
                ->where('ends_on', '>=', $paymentDate)
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
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $paymentAmount = '0.00';

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get((int) $allocation['invoice_id']);

                if (! $invoice
                    || $invoice->type !== InvoiceType::Purchase
                    || (int) $invoice->supplier_id !== $supplier->id
                    || ! in_array($invoice->status, [
                        InvoiceStatus::Confirmed,
                        InvoiceStatus::PartiallyPaid,
                    ], true)) {
                    throw new DomainException('فاکتور انتخاب‌شده برای پرداخت معتبر نیست.');
                }

                $amount = Decimal::normalize($allocation['amount'], Decimal::MONEY_SCALE);

                if (! Decimal::isPositive($amount)
                    || Decimal::compare($amount, $this->outstandingFor($invoice), Decimal::MONEY_SCALE) > 0) {
                    throw new DomainException('مبلغ تخصیص از مانده فاکتور بیشتر است.');
                }

                $paymentAmount = Decimal::add($paymentAmount, $amount, Decimal::MONEY_SCALE);
            }

            $check = null;

            if ($paymentMethod === PaymentMethod::Check) {
                $check = Check::query()->lockForUpdate()->findOrFail($checkId);

                if ($check->type !== CheckType::Payable
                    || (int) $check->supplier_id !== $supplier->id
                    || (int) $check->store_id !== $storeId
                    || Decimal::compare($check->amount, $paymentAmount, Decimal::MONEY_SCALE) !== 0) {
                    throw new DomainException('چک پرداختی با تأمین‌کننده یا مبلغ پرداخت سازگار نیست.');
                }
            } elseif ($checkId !== null) {
                throw new DomainException('برای روش‌های غیرچکی نباید شناسه چک ارسال شود.');
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
                'payment_date' => $paymentDate,
                'amount' => $paymentAmount,
                'payment_method' => $paymentMethod,
                'supplier_id' => $supplier->id,
                'check_id' => $check?->id,
                'created_by' => $approver->id,
                'reference' => $reference,
                'status' => TreasuryDocumentStatus::Pending,
                'description' => $description,
            ]);

            foreach ($allocations as $allocation) {
                PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => Decimal::normalize($allocation['amount'], Decimal::MONEY_SCALE),
                ]);
            }

            $creditAccount = match ($paymentMethod) {
                PaymentMethod::Cash => 'cash',
                PaymentMethod::Bank, PaymentMethod::Card => 'bank',
                PaymentMethod::Check => 'checks_payable',
            };

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
                        'account_id' => $this->settings->defaultAccountId($creditAccount, $storeId),
                        'debit' => '0.00',
                        'credit' => $paymentAmount,
                        'description' => 'منبع پرداخت',
                    ],
                ],
                $approver,
            );

            $payment->forceFill(['status' => TreasuryDocumentStatus::Confirmed])->save();
            $check?->forceFill(['status' => CheckStatus::Spent])->save();

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get((int) $allocation['invoice_id']);
                $outstanding = $this->outstandingFor($invoice);
                $invoice->forceFill([
                    'status' => Decimal::isZero($outstanding)
                        ? InvoiceStatus::Paid
                        : InvoiceStatus::PartiallyPaid,
                ])->save();
            }

            return $payment->refresh()->load(['allocations.invoice', 'supplier.party', 'check']);
        });
    }

    private function outstandingFor(Invoice $invoice): string
    {
        $paid = (string) $invoice->paymentAllocations()
            ->whereHas('payment', function ($query): void {
                $query->where('status', TreasuryDocumentStatus::Confirmed);
            })
            ->sum('amount');
        $returned = (string) Invoice::query()
            ->where('parent_invoice_id', $invoice->id)
            ->where('type', InvoiceType::PurchaseReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->sum('total');
        $outstanding = Decimal::subtract($invoice->total, $paid, Decimal::MONEY_SCALE);
        $outstanding = Decimal::subtract($outstanding, $returned, Decimal::MONEY_SCALE);

        return Decimal::compare($outstanding, '0', Decimal::MONEY_SCALE) > 0
            ? $outstanding
            : '0.00';
    }
}
