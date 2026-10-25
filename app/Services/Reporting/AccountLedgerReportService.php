<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        $range = ReportDateRange::between($from, $to);
        $base = $this->baseQuery($account, $storeId, $branchId);
        $summary = $this->summary($base, $range);
        $lines = $this->periodQuery($base, $range)->get();

        return [
            'account' => $account,
            ...$summary,
            'rows' => $this->rows($lines, $summary['opening_balance']),
        ];
    }

    /** @return array<string, mixed> */
    public function pageReport(
        Account $account,
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
        int $page = 1,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $base = $this->baseQuery($account, $storeId, $branchId);
        $summary = $this->summary($base, $range);
        $query = $this->periodQuery($base, $range);
        $lines = (clone $query)->paginate(15, ['*'], 'ledger_page', max(1, $page))
            ->withQueryString();
        $running = $summary['opening_balance'];
        $offset = ($lines->currentPage() - 1) * $lines->perPage();

        if ($offset > 0 && $lines->isNotEmpty()) {
            // Carry forward earlier rows without loading them into PHP memory.
            $previous = (clone $query)->withoutEagerLoads()
                ->select(['journal_entry_lines.debit', 'journal_entry_lines.credit'])
                ->limit($offset);
            $totals = DB::query()->fromSub($previous, 'previous_ledger_rows')
                ->selectRaw('SUM(debit) AS debit_total, SUM(credit) AS credit_total')
                ->first();
            $running = Decimal::add($running, (string) ($totals->debit_total ?? 0), 2);
            $running = Decimal::subtract($running, (string) ($totals->credit_total ?? 0), 2);
        }

        $lines->setCollection($this->rows($lines->getCollection(), $running));

        return ['account' => $account, ...$summary, 'rows' => $lines];
    }

    /** @return Builder<JournalEntryLine> */
    private function baseQuery(Account $account, int $storeId, ?int $branchId): Builder
    {
        if ((int) $account->store_id !== $storeId) {
            throw new DomainException('حساب گزارش متعلق به فروشگاه درخواستی نیست.');
        }

        $accountIds = $account->isGeneral()
            ? $account->children()->pluck('id')
            : collect([$account->id]);

        return JournalEntryLine::query()
            ->select('journal_entry_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('journal_entries.branch_id', $branchId));
    }

    /**
     * @param  Builder<JournalEntryLine>  $base
     * @return array{opening_balance: string, debit_total: string, credit_total: string, closing_balance: string}
     */
    private function summary(Builder $base, ReportDateRange $range): array
    {
        $opening = (clone $base)->where('journal_entries.entry_date', '<', $range->from);
        $period = (clone $base)
            ->whereDate('journal_entries.entry_date', '>=', $range->from)
            ->whereDate('journal_entries.entry_date', '<=', $range->to);
        $openingBalance = Decimal::subtract(
            (string) (clone $opening)->sum('journal_entry_lines.debit'),
            (string) (clone $opening)->sum('journal_entry_lines.credit'),
            2,
        );
        $debit = Decimal::normalize((string) (clone $period)->sum('journal_entry_lines.debit'), 2);
        $credit = Decimal::normalize((string) (clone $period)->sum('journal_entry_lines.credit'), 2);

        return [
            'opening_balance' => $openingBalance,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'closing_balance' => Decimal::subtract(Decimal::add($openingBalance, $debit, 2), $credit, 2),
        ];
    }

    /**
     * @param  Builder<JournalEntryLine>  $base
     * @return Builder<JournalEntryLine>
     */
    private function periodQuery(Builder $base, ReportDateRange $range): Builder
    {
        return (clone $base)
            ->whereDate('journal_entries.entry_date', '>=', $range->from)
            ->whereDate('journal_entries.entry_date', '<=', $range->to)
            ->with(['journalEntry', 'account'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_number')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.sort_order')
            ->orderBy('journal_entry_lines.id');
    }

    /**
     * @param  Collection<int, JournalEntryLine>  $lines
     * @return Collection<int, mixed>
     */
    private function rows(Collection $lines, string $running): Collection
    {
        return $lines->map(function (JournalEntryLine $line) use (&$running): array {
            $running = Decimal::add($running, $line->debit, 2);
            $running = Decimal::subtract($running, $line->credit, 2);

            return [
                'id' => $line->id,
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
        })->values();
    }
}
