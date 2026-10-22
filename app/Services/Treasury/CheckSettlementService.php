<?php

namespace App\Services\Treasury;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\AccountingPeriod;
use App\Models\Check;
use App\Models\Invoice;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class CheckSettlementService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
    ) {}

    public function collectReceivable(
        Check $check,
        string $settlementDate,
        User $approver,
        ?int $treasuryAccountId = null,
    ): Check {
        return $this->settle($check, $settlementDate, $approver, $treasuryAccountId, CheckType::Receivable);
    }

    public function settlePayable(
        Check $check,
        string $settlementDate,
        User $approver,
        ?int $treasuryAccountId = null,
    ): Check {
        return $this->settle($check, $settlementDate, $approver, $treasuryAccountId, CheckType::Payable);
    }

    public function bounce(
        Check $check,
        string $bounceDate,
        User $approver,
    ): Check {
        return DB::transaction(function () use ($check, $bounceDate, $approver): Check {
            $locked = Check::query()->lockForUpdate()->findOrFail($check->id);

            if ($locked->status === CheckStatus::Bounced) {
                return $locked;
            }

            if ($locked->status !== CheckStatus::Pending) {
                throw new DomainException('فقط چک در انتظار وصول یا خرج قابل برگشت است.');
            }

            $isReceivable = $locked->type === CheckType::Receivable;
            $treasuryDocument = null;

            if ($isReceivable) {
                $receipt = $locked->receipt()->with('allocations.invoice')->first();

                if (! $receipt || $receipt->status !== TreasuryDocumentStatus::Confirmed) {
                    throw new DomainException('سند خزانه تأییدشده برای این چک پیدا نشد.');
                }

                $treasuryDocument = $receipt;
                $partyId = $receipt->customer->party_id;
            } else {
                $payment = $locked->payment()->with('allocations.invoice')->first();

                if (! $payment || $payment->status !== TreasuryDocumentStatus::Confirmed) {
                    throw new DomainException('سند خزانه تأییدشده برای این چک پیدا نشد.');
                }

                $treasuryDocument = $payment;
                $partyId = $payment->supplier->party_id;
            }

            $period = $this->periodFor($treasuryDocument->store_id, $treasuryDocument->fiscal_year_id, $bounceDate);
            $lines = $isReceivable
                ? [
                    [
                        'account_id' => $this->settings->defaultAccountId('accounts_receivable', $locked->store_id),
                        'party_id' => $partyId,
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'برگشت چک دریافتی و ایجاد مطالبه',
                    ],
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_receivable', $locked->store_id),
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'کاهش چک‌های دریافتنی',
                    ],
                ]
                : [
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_payable', $locked->store_id),
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'برگشت چک پرداختنی',
                    ],
                    [
                        'account_id' => $this->settings->defaultAccountId('accounts_payable', $locked->store_id),
                        'party_id' => $partyId,
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'احیای بدهی تأمین‌کننده',
                    ],
                ];
            $entry = $this->posting->post(
                [
                    'store_id' => $treasuryDocument->store_id,
                    'branch_id' => $treasuryDocument->branch_id,
                    'fiscal_year_id' => $treasuryDocument->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $bounceDate,
                    'description' => "برگشت چک {$locked->check_number}",
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
            );

            $treasuryDocument->forceFill(['status' => TreasuryDocumentStatus::Bounced])->save();
            $locked->forceFill([
                'status' => CheckStatus::Bounced,
                'bounced_at' => now(),
                'bounce_entry_id' => $entry->id,
            ])->save();

            foreach ($treasuryDocument->allocations as $allocation) {
                $this->refreshInvoiceStatus($allocation->invoice);
            }

            return $locked->refresh();
        });
    }

    private function settle(
        Check $check,
        string $settlementDate,
        User $approver,
        ?int $treasuryAccountId,
        CheckType $expectedType,
    ): Check {
        return DB::transaction(function () use (
            $check,
            $settlementDate,
            $approver,
            $treasuryAccountId,
            $expectedType,
        ): Check {
            $locked = Check::query()->lockForUpdate()->findOrFail($check->id);

            if ($locked->type !== $expectedType) {
                throw new DomainException('نوع چک با عملیات درخواستی سازگار نیست.');
            }

            if ($locked->status === CheckStatus::Collected || $locked->status === CheckStatus::Spent) {
                return $locked;
            }

            if ($locked->status !== CheckStatus::Pending) {
                throw new DomainException('فقط چک در انتظار قابل وصول یا خرج است.');
            }

            $treasuryDocument = $expectedType === CheckType::Receivable
                ? $locked->receipt()->first()
                : $locked->payment()->first();

            if (! $treasuryDocument || $treasuryDocument->status !== TreasuryDocumentStatus::Confirmed) {
                throw new DomainException('سند خزانه تأییدشده برای این چک پیدا نشد.');
            }

            $treasuryAccount = TreasuryAccount::query()
                ->where('store_id', $treasuryDocument->store_id)
                ->where('branch_id', $treasuryDocument->branch_id)
                ->where('type', TreasuryAccountType::Bank)
                ->where('is_active', true)
                ->when($treasuryAccountId !== null, fn ($query) => $query->whereKey($treasuryAccountId))
                ->orderByDesc('is_default')
                ->lockForUpdate()
                ->first();

            if (! $treasuryAccount) {
                throw new DomainException('حساب بانکی فعال برای تسویه چک پیدا نشد.');
            }

            $period = $this->periodFor($treasuryDocument->store_id, $treasuryDocument->fiscal_year_id, $settlementDate);
            $isReceivable = $expectedType === CheckType::Receivable;
            $lines = $isReceivable
                ? [
                    [
                        'account_id' => $treasuryAccount->gl_account_id,
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'وصول چک دریافتی',
                    ],
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_receivable', $locked->store_id),
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'تسویه چک‌های دریافتنی',
                    ],
                ]
                : [
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_payable', $locked->store_id),
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'خرج چک پرداختنی',
                    ],
                    [
                        'account_id' => $treasuryAccount->gl_account_id,
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'کاهش حساب بانکی',
                    ],
                ];
            $entry = $this->posting->post(
                [
                    'store_id' => $treasuryDocument->store_id,
                    'branch_id' => $treasuryDocument->branch_id,
                    'fiscal_year_id' => $treasuryDocument->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $settlementDate,
                    'description' => "تسویه چک {$locked->check_number}",
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
            );

            $locked->forceFill([
                'status' => $isReceivable ? CheckStatus::Collected : CheckStatus::Spent,
                'settled_at' => now(),
                'settlement_entry_id' => $entry->id,
            ])->save();

            return $locked->refresh();
        });
    }

    private function periodFor(int $storeId, int $fiscalYearId, string $date): AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('store_id', $storeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->firstOrFail();
    }

    private function refreshInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->type !== InvoiceType::Sales && $invoice->type !== InvoiceType::Purchase) {
            return;
        }

        $isSales = $invoice->type === InvoiceType::Sales;
        $settled = $isSales
            ? (string) $invoice->receiptAllocations()
                ->whereHas('receipt', fn ($query) => $query->where('status', TreasuryDocumentStatus::Confirmed))
                ->sum('amount')
            : (string) $invoice->paymentAllocations()
                ->whereHas('payment', fn ($query) => $query->where('status', TreasuryDocumentStatus::Confirmed))
                ->sum('amount');
        $returned = (string) Invoice::query()
            ->where('parent_invoice_id', $invoice->id)
            ->where('type', $isSales ? InvoiceType::SalesReturn : InvoiceType::PurchaseReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->sum('total');
        $remaining = Decimal::subtract($invoice->total, $settled, 2);
        $remaining = Decimal::subtract($remaining, $returned, 2);
        $status = Decimal::compare($remaining, '0', 2) <= 0
            ? InvoiceStatus::Paid
            : (Decimal::isPositive($settled) ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Confirmed);
        $invoice->forceFill(['status' => $status])->save();
    }
}
