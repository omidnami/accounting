<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use DomainException;
use Illuminate\Support\Collection;

class AccountLedgerReportService
{
    /**
     * @return array{
     *     account: Account,
     *     opening_balance: string,
     *     debit_total: string,
     *     credit_total: string,
     *     closing_balance: string,
     *     rows: Collection<int, mixed>
     * }
     */
    public function report(
        Account $account,
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): array {
        if ((int) $account->store_id !== $storeId) {
            throw new DomainException('حساب گزارش متعلق به فروشگاه درخواستی نیست.');
        }

        $range = ReportDateRange::between($from, $to);
        $accountIds = $account->isGeneral()
            ? $account->children()->pluck('id')
            : collect([$account->id]);

        $baseQuery = fn () => JournalEntryLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($query) use ($storeId, $branchId): void {
                $query->posted()
                    ->where('store_id', $storeId)
                    ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));
            });
        $openingDebit = (string) $baseQuery()
            ->whereHas('journalEntry', fn ($query) => $query->where('entry_date', '<', $range->from))
            ->sum('debit');
        $openingCredit = (string) $baseQuery()
            ->whereHas('journalEntry', fn ($query) => $query->where('entry_date', '<', $range->from))
            ->sum('credit');
        $lines = $baseQuery()
            ->whereHas('journalEntry', fn ($query) => $query->whereBetween('entry_date', [$range->from, $range->to]))
            ->with(['journalEntry', 'account'])
            ->get()
            ->sortBy([
                fn ($line) => $line->journalEntry->entry_date->toDateString(),
                fn ($line) => $line->journalEntry->entry_number,
                fn ($line) => $line->sort_order,
            ])
            ->values();
        $running = Decimal::subtract($openingDebit, $openingCredit, 2);
        $debitTotal = '0.00';
        $creditTotal = '0.00';
        $rows = $lines->map(function (JournalEntryLine $line) use (&$running, &$debitTotal, &$creditTotal): array {
            $debitTotal = Decimal::add($debitTotal, $line->debit, 2);
            $creditTotal = Decimal::add($creditTotal, $line->credit, 2);
            $running = Decimal::add($running, $line->debit, 2);
            $running = Decimal::subtract($running, $line->credit, 2);

            return [
                'entry_id' => $line->journalEntry->id,
                'entry_number' => $line->journalEntry->entry_number,
                'account_code' => $line->account->code,
                'account_name' => $line->account->name,
                'date' => $line->journalEntry->entry_date->toDateString(),
                'description' => $line->description ?: $line->journalEntry->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $running,
            ];
        });

        return [
            'account' => $account,
            'opening_balance' => Decimal::subtract($openingDebit, $openingCredit, 2),
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'closing_balance' => $running,
            'rows' => $rows,
        ];
    }
}
