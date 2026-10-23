<?php

namespace App\Services\Treasury;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
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
use Carbon\CarbonImmutable;
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
                $this->assertBounceRequestMatches($locked, $bounceDate);

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

            $period = $this->periodFor($treasuryDocument->store_id, $bounceDate);
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
                    'fiscal_year_id' => $period->fiscal_year_id,
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

            $locked->markBounced($entry);
            $treasuryDocument->markBounced();

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
                $this->assertSettlementRequestMatches(
                    $locked,
                    $settlementDate,
                    $treasuryAccountId,
                );

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

            $period = $this->periodFor($treasuryDocument->store_id, $settlementDate);
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
                    'fiscal_year_id' => $period->fiscal_year_id,
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

            $locked->markSettled($entry, $treasuryAccount);

            return $locked->refresh();
        });
    }

    private function periodFor(int $storeId, string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->where('store_id', $storeId)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if (! $period) {
            throw new DomainException('برای تاریخ عملیات چک، دوره مالی باز یا تعریف‌شده‌ای وجود ندارد.');
        }

        return $period;
    }

    private function refreshInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->type !== InvoiceType::Sales && $invoice->type !== InvoiceType::Purchase) {
            return;
        }

        $invoice->syncSettlementStatus();
    }

    private function assertSettlementRequestMatches(
        Check $check,
        string $settlementDate,
        ?int $treasuryAccountId,
    ): void {
        $entry = $check->settlementEntry()->first();
        $requestedDate = CarbonImmutable::parse($settlementDate)->toDateString();

        if (! $entry || $entry->entry_date->toDateString() !== $requestedDate) {
            throw new DomainException('این چک قبلاً با تاریخ دیگری وصول یا خرج شده است.');
        }

        if ($treasuryAccountId === null) {
            return;
        }

        if ($treasuryAccountId !== $check->settlement_treasury_account_id) {
            throw new DomainException('این چک قبلاً با حساب بانکی دیگری وصول یا خرج شده است.');
        }
    }

    private function assertBounceRequestMatches(Check $check, string $bounceDate): void
    {
        $entry = $check->bounceEntry()->first();
        $requestedDate = CarbonImmutable::parse($bounceDate)->toDateString();

        if (! $entry || $entry->entry_date->toDateString() !== $requestedDate) {
            throw new DomainException('این چک قبلاً با تاریخ دیگری برگشت خورده است.');
        }
    }
}
