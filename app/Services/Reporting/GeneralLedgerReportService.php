<?php

namespace App\Services\Reporting;

use App\Support\Decimal;
use App\Support\ReportDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class GeneralLedgerReportService
{
    /** @return array<string, mixed> */
    public function pageReport(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $query = $this->baseQuery($storeId, $range, $branchId);
        $summary = $this->summary($storeId, $range, $branchId);
        $lines = (clone $query)
            ->paginate(min(200, max(1, $perPage)), ['*'], 'gl_page', max(1, $page))
            ->withQueryString();

        return [
            ...$summary,
            'rows' => [
                'data' => $lines->getCollection()
                    ->map(fn (object $line): array => $this->row((array) $line))
                    ->values()
                    ->all(),
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
                'total' => $lines->total(),
                'prev_page_url' => $lines->previousPageUrl(),
                'next_page_url' => $lines->nextPageUrl(),
            ],
        ];
    }

    /** @return LazyCollection<int, array<int, string>> */
    public function exportRows(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): LazyCollection {
        $range = ReportDateRange::between($from, $to);
        $summary = $this->summary($storeId, $range, $branchId);

        return LazyCollection::make(function () use ($storeId, $range, $branchId, $summary): \Generator {
            foreach ($this->baseQuery($storeId, $range, $branchId)->lazy(500) as $line) {
                $row = $this->row((array) $line);

                yield [
                    $row['date'],
                    $row['entry_number'],
                    $row['description'],
                    $row['account_code'],
                    $row['account_name'],
                    $row['debit'],
                    $row['credit'],
                ];
            }

            yield ['', '', 'جمع کل', '', '', $summary['debit_total'], $summary['credit_total']];
        });
    }

    private function baseQuery(int $storeId, ReportDateRange $range, ?int $branchId): Builder
    {
        return $this->query($storeId, $range, $branchId)
            ->select([
                'journal_entry_lines.id as line_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entry_lines.description as line_description',
                'journal_entries.id as entry_id',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
                'accounts.code as account_code',
                'accounts.name as account_name',
            ])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_number')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.sort_order')
            ->orderBy('journal_entry_lines.id');
    }

    private function query(int $storeId, ReportDateRange $range, ?int $branchId): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.store_id', $storeId)
            ->whereDate('journal_entries.entry_date', '>=', $range->from)
            ->whereDate('journal_entries.entry_date', '<=', $range->to)
            ->when($branchId !== null, fn (Builder $query) => $query->where('journal_entries.branch_id', $branchId));
    }

    /** @return array{debit_total: string, credit_total: string} */
    private function summary(int $storeId, ReportDateRange $range, ?int $branchId): array
    {
        $totals = $this->query($storeId, $range, $branchId)
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) AS credit_total')
            ->first();

        return [
            'debit_total' => Decimal::normalize((string) ($totals->debit_total ?? 0), 2),
            'credit_total' => Decimal::normalize((string) ($totals->credit_total ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{id: int, entry_id: int, entry_number: string, date: string, description: string, account_code: string, account_name: string, debit: string, credit: string}
     */
    private function row(array $line): array
    {
        return [
            'id' => (int) $line['line_id'],
            'entry_id' => (int) $line['entry_id'],
            'entry_number' => $line['entry_number'],
            'date' => substr((string) $line['entry_date'], 0, 10),
            'description' => $line['line_description'] ?: $line['entry_description'],
            'account_code' => $line['account_code'],
            'account_name' => $line['account_name'],
            'debit' => Decimal::normalize($line['debit'], 2),
            'credit' => Decimal::normalize($line['credit'], 2),
        ];
    }
}
