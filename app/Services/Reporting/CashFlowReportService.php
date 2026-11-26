<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class CashFlowReportService
{
    /**
     * @return array{
     *     opening_balance: string, inflow_total: string, outflow_total: string,
     *     net_change: string, closing_balance: string,
     *     rows: array<int, array{period: string, inflow: string, outflow: string, net: string}>
     * }
     */
    public function report(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $accountIds = $this->cashAccountIds($storeId);
        $opening = '0.00';
        $inflow = '0.00';
        $outflow = '0.00';
        $rows = [];

        if ($accountIds !== []) {
            $base = $this->baseQuery($storeId, $accountIds, $branchId);
            $openingDebit = (string) (clone $base)
                ->whereDate('journal_entries.entry_date', '<', $range->from)
                ->sum('journal_entry_lines.debit');
            $openingCredit = (string) (clone $base)
                ->whereDate('journal_entries.entry_date', '<', $range->from)
                ->sum('journal_entry_lines.credit');
            $period = (clone $base)
                ->whereDate('journal_entries.entry_date', '>=', $range->from)
                ->whereDate('journal_entries.entry_date', '<=', $range->to);
            $inflow = Decimal::normalize((string) (clone $period)->sum('journal_entry_lines.debit'), 2);
            $outflow = Decimal::normalize((string) (clone $period)->sum('journal_entry_lines.credit'), 2);
            $opening = Decimal::subtract($openingDebit, $openingCredit, 2);
            $monthExpression = DB::connection()->getDriverName() === 'sqlite'
                ? "strftime('%Y-%m', journal_entries.entry_date)"
                : "DATE_FORMAT(journal_entries.entry_date, '%Y-%m')";

            $rows = (clone $period)
                ->selectRaw("{$monthExpression} AS period")
                ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) AS inflow')
                ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) AS outflow')
                ->groupByRaw($monthExpression)
                ->orderBy('period')
                ->get()
                ->map(fn (object $row): array => [
                    'period' => $row->period,
                    'inflow' => Decimal::normalize($row->inflow, 2),
                    'outflow' => Decimal::normalize($row->outflow, 2),
                    'net' => Decimal::subtract($row->inflow, $row->outflow, 2),
                ])
                ->all();
        }

        $net = Decimal::subtract($inflow, $outflow, 2);

        return [
            'opening_balance' => $opening,
            'inflow_total' => $inflow,
            'outflow_total' => $outflow,
            'net_change' => $net,
            'closing_balance' => Decimal::add($opening, $net, 2),
            'rows' => $rows,
        ];
    }

    /** @return LazyCollection<int, array<int, string>> */
    public function exportRows(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): LazyCollection {
        $data = $this->report($storeId, $from, $to, $branchId);

        return LazyCollection::make(function () use ($data): \Generator {
            yield ['مانده اول دوره', '', '', $data['opening_balance']];

            foreach ($data['rows'] as $row) {
                yield [$row['period'], $row['inflow'], $row['outflow'], $row['net']];
            }

            yield ['جمع دوره', $data['inflow_total'], $data['outflow_total'], $data['net_change']];
            yield ['مانده پایان دوره', '', '', $data['closing_balance']];
        });
    }

    /** @return array<int, int> */
    private function cashAccountIds(int $storeId): array
    {
        $codes = array_values(array_unique(array_filter([
            config('accounting.treasury_account_gl_codes.cash'),
            config('accounting.treasury_account_gl_codes.bank'),
        ])));

        return Account::query()
            ->where('store_id', $storeId)
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $accountIds
     */
    private function baseQuery(int $storeId, array $accountIds, ?int $branchId): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.store_id', $storeId)
            ->when($branchId !== null, fn (Builder $query) => $query->where('journal_entries.branch_id', $branchId));
    }
}
