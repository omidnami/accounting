<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class YearEndClosingService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return Collection<int, JournalEntry>
     */
    public function createClosingEntries(
        Store $store,
        FiscalYear $fiscalYear,
        User $actor,
        string $reason,
    ): Collection {
        return DB::transaction(function () use ($store, $fiscalYear, $actor, $reason): Collection {
            $lockedYear = FiscalYear::query()
                ->whereKey($fiscalYear->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->firstOrFail();
            $period = $this->lastPeriod($lockedYear);
            $wasClosed = $period->status === AccountingPeriodStatus::Closed;
            $entries = collect();

            if ($wasClosed) {
                $period->transitionStatus(AccountingPeriodStatus::Open);
            }

            try {
                foreach ($this->branches($store) as $branch) {
                    $active = $this->activeClosingEntry($lockedYear, $branch);

                    if ($active) {
                        $entries->push($active);

                        continue;
                    }

                    $lines = $this->closingLines($store, $lockedYear, $branch);

                    if ($lines === []) {
                        continue;
                    }

                    $entry = $this->posting->post(
                        [
                            'store_id' => $store->id,
                            'branch_id' => $branch->id,
                            'fiscal_year_id' => $lockedYear->id,
                            'accounting_period_id' => $period->id,
                            'entry_kind' => JournalEntryKind::Closing,
                            'entry_date' => $lockedYear->ends_on->toDateString(),
                            'description' => "سند اختتامیه سال {$lockedYear->title}",
                            'source_type' => $lockedYear->getMorphClass(),
                            'source_id' => $lockedYear->id,
                            'created_by' => $actor->id,
                        ],
                        $lines,
                        $actor,
                    );
                    $entries->push($entry);
                    $this->auditLogs->record(
                        'fiscal_year_closing_posted',
                        $entry,
                        [],
                        [
                            'fiscal_year_id' => $lockedYear->id,
                            'branch_id' => $branch->id,
                            'reason' => trim($reason),
                        ],
                        trim($reason),
                    );
                }
            } finally {
                if ($wasClosed) {
                    $period->transitionStatus(AccountingPeriodStatus::Closed, $actor->id);
                }
            }

            return $entries;
        }, attempts: 3);
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    public function reverseClosingEntries(
        Store $store,
        FiscalYear $fiscalYear,
        User $actor,
        string $reason,
    ): Collection {
        return DB::transaction(function () use ($store, $fiscalYear, $actor, $reason): Collection {
            $lockedYear = FiscalYear::query()
                ->whereKey($fiscalYear->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->firstOrFail();
            $period = $this->lastPeriod($lockedYear);
            $wasClosed = $period->status !== AccountingPeriodStatus::Open;
            $entries = collect();

            if ($wasClosed) {
                $period->transitionStatus(AccountingPeriodStatus::Open);
            }

            try {
                $closings = JournalEntry::query()
                    ->where('store_id', $store->id)
                    ->where('fiscal_year_id', $lockedYear->id)
                    ->where('entry_kind', JournalEntryKind::Closing)
                    ->where('status', JournalEntryStatus::Posted)
                    ->where('source_type', $lockedYear->getMorphClass())
                    ->where('source_id', $lockedYear->id)
                    ->whereDoesntHave('reversal')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($closings as $closing) {
                    $closing->load(['lines', 'branch']);
                    $reversal = $this->posting->postReversal(
                        $closing,
                        $actor,
                        [
                            'description' => "برگشت سند اختتامیه سال {$lockedYear->title}",
                        ],
                    );
                    $entries->push($reversal);
                    $this->auditLogs->record(
                        'fiscal_year_closing_reversed',
                        $reversal,
                        [],
                        [
                            'fiscal_year_id' => $lockedYear->id,
                            'closing_entry_id' => $closing->id,
                            'reason' => trim($reason),
                        ],
                        trim($reason),
                    );
                }
            } finally {
                if ($wasClosed) {
                    $period->transitionStatus(AccountingPeriodStatus::Closed, $actor->id);
                }
            }

            return $entries;
        }, attempts: 3);
    }

    private function lastPeriod(FiscalYear $fiscalYear): AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('store_id', $fiscalYear->store_id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->orderByDesc('period_number')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return Collection<int, Branch>
     */
    private function branches(Store $store): Collection
    {
        return Branch::query()
            ->where('store_id', $store->id)
            ->orderBy('id')
            ->get();
    }

    private function activeClosingEntry(
        FiscalYear $fiscalYear,
        Branch $branch,
    ): ?JournalEntry {
        return JournalEntry::query()
            ->where('store_id', $fiscalYear->store_id)
            ->where('branch_id', $branch->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('entry_kind', JournalEntryKind::Closing)
            ->where('status', JournalEntryStatus::Posted)
            ->where('source_type', $fiscalYear->getMorphClass())
            ->where('source_id', $fiscalYear->id)
            ->whereDoesntHave('reversal')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function closingLines(
        Store $store,
        FiscalYear $fiscalYear,
        Branch $branch,
    ): array {
        $totals = JournalEntryLine::query()
            ->select('account_id')
            ->selectRaw('SUM(debit) AS debit_total, SUM(credit) AS credit_total')
            ->whereHas('journalEntry', function ($query) use ($store, $fiscalYear, $branch): void {
                $query->posted()
                    ->where('store_id', $store->id)
                    ->where('branch_id', $branch->id)
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->whereDate('entry_date', '<=', $fiscalYear->ends_on->toDateString());
            })
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');
        $accounts = Account::query()
            ->where('store_id', $store->id)
            ->where('level', AccountLevel::Subsidiary)
            ->whereIn('type', [AccountType::Revenue, AccountType::Expense])
            ->whereIn('id', $totals->keys())
            ->orderBy('code')
            ->get();
        $lines = [];
        $netProfit = '0.00';

        foreach ($accounts as $account) {
            $debit = Decimal::normalize($totals[$account->id]->debit_total ?? 0, 2);
            $credit = Decimal::normalize($totals[$account->id]->credit_total ?? 0, 2);
            $amount = $account->type === AccountType::Revenue
                ? Decimal::subtract($credit, $debit, 2)
                : Decimal::subtract($debit, $credit, 2);

            if (Decimal::isZero($amount)) {
                continue;
            }

            $netProfit = $account->type === AccountType::Revenue
                ? Decimal::add($netProfit, $amount, 2)
                : Decimal::subtract($netProfit, $amount, 2);
            $isPositive = Decimal::compare($amount, '0', 2) > 0;
            $absolute = $isPositive ? $amount : ltrim($amount, '-');
            $lines[] = [
                'account_id' => $account->id,
                'debit' => ($account->type === AccountType::Revenue) === $isPositive
                    ? $absolute
                    : '0.00',
                'credit' => ($account->type === AccountType::Revenue) === $isPositive
                    ? '0.00'
                    : $absolute,
                'description' => 'بستن حساب موقت سالانه',
            ];
        }

        if ($lines === []) {
            return [];
        }

        if (! Decimal::isZero($netProfit)) {
            $isProfit = Decimal::compare($netProfit, '0', 2) > 0;
            $amount = $isProfit ? $netProfit : ltrim($netProfit, '-');
            $lines[] = [
                'account_id' => $this->settings->defaultAccountId('retained_earnings', $store->id),
                'debit' => $isProfit ? '0.00' : $amount,
                'credit' => $isProfit ? $amount : '0.00',
                'description' => $isProfit ? 'انتقال سود سال به سود انباشته' : 'انتقال زیان سال به سود انباشته',
            ];
        }

        $debitTotal = collect($lines)->reduce(
            fn (string $total, array $line): string => Decimal::add($total, $line['debit'], 2),
            '0.00',
        );
        $creditTotal = collect($lines)->reduce(
            fn (string $total, array $line): string => Decimal::add($total, $line['credit'], 2),
            '0.00',
        );

        if (Decimal::compare($debitTotal, $creditTotal, 2) !== 0) {
            throw new DomainException('سند اختتامیه متوازن نیست.');
        }

        return $lines;
    }
}
