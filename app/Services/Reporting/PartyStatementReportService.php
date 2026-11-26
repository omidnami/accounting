<?php

namespace App\Services\Reporting;

use App\Models\Party;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class PartyStatementReportService
{
    /**
     * @return array{
     *     party: array{id: int, code: string, name: string, kind: string},
     *     opening_balance: string,
     *     debit_total: string,
     *     credit_total: string,
     *     closing_balance: string,
     *     rows: Collection<int, mixed>
     * }
     */
    public function report(
        Party $party,
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $summary = $this->summary($party, $storeId, $range, $branchId);
        $running = $summary['opening_balance'];
        $rows = $this->lineRows($party, $storeId, $range, $branchId)
            ->map(function (object $line) use (&$running): array {
                $row = $this->row((array) $line, $running);
                $running = $row['balance'];

                return $row;
            });

        return [
            'party' => $this->party($party),
            ...$summary,
            'rows' => $rows->collect(),
        ];
    }

    /** @return array<string, mixed> */
    public function pageReport(
        Party $party,
        int $storeId,
        string $from,
        string $to,
        ?int $branchId,
        int $page,
    ): array {
        $page = max(1, $page);
        $build = function () use ($party, $storeId, $from, $to, $branchId, $page): array {
            $range = ReportDateRange::between($from, $to);
            $summary = $this->summary($party, $storeId, $range, $branchId);
            $query = $this->periodQuery($party, $storeId, $range, $branchId);
            $lines = (clone $query)->paginate(50, ['*'], 'statement_page', $page)
                ->withQueryString();
            $running = $summary['opening_balance'];
            $offset = ($lines->currentPage() - 1) * $lines->perPage();

            if ($offset > 0 && $lines->isNotEmpty()) {
                $previous = (clone $query)
                    ->select(['journal_entry_lines.debit', 'journal_entry_lines.credit'])
                    ->limit($offset);
                $totals = DB::query()->fromSub($previous, 'previous_statement_rows')
                    ->selectRaw('SUM(debit) AS debit_total, SUM(credit) AS credit_total')
                    ->first();
                $running = Decimal::add($running, (string) ($totals->debit_total ?? 0), 2);
                $running = Decimal::subtract($running, (string) ($totals->credit_total ?? 0), 2);
            }

            $rows = $lines->getCollection()
                ->map(function (object $line) use (&$running): array {
                    $row = $this->row((array) $line, $running);
                    $running = $row['balance'];

                    return $row;
                })
                ->values()
                ->all();

            return [
                'party' => $this->party($party),
                ...$summary,
                'rows' => [
                    'data' => $rows,
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
        };

        return app()->environment('testing')
            ? $build()
            : Cache::remember(
                "party-statement:v3:{$storeId}:{$branchId}:{$party->id}:{$from}:{$to}:page:{$page}",
                now()->addMinute(),
                $build,
            );
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function exportRows(
        Party $party,
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): LazyCollection {
        $range = ReportDateRange::between($from, $to);
        $summary = $this->summary($party, $storeId, $range, $branchId);

        return LazyCollection::make(function () use ($party, $storeId, $range, $branchId, $summary): \Generator {
            $running = $summary['opening_balance'];

            yield [
                '', '', 'مانده اول دوره', '', '', '', '', $summary['opening_balance'],
            ];

            foreach ($this->lineRows($party, $storeId, $range, $branchId) as $line) {
                $row = $this->row((array) $line, $running);
                $running = $row['balance'];

                yield [
                    $row['date'],
                    $row['entry_number'],
                    $row['description'],
                    $row['account_code'],
                    $row['account_name'],
                    $row['debit'],
                    $row['credit'],
                    $row['balance'],
                ];
            }

            yield [
                '', '', 'جمع گردش و مانده پایان', '', '',
                $summary['debit_total'], $summary['credit_total'], $summary['closing_balance'],
            ];
        });
    }

    private function baseQuery(Party $party, int $storeId, ?int $branchId): Builder
    {
        if ((int) $party->store_id !== $storeId) {
            throw new DomainException('طرف‌حساب متعلق به این فروشگاه نیست.');
        }

        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.party_id', $party->id)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.store_id', $storeId)
            ->when($branchId !== null, fn (Builder $query) => $query->where('journal_entries.branch_id', $branchId));
    }

    private function periodQuery(Party $party, int $storeId, ReportDateRange $range, ?int $branchId): Builder
    {
        return $this->baseQuery($party, $storeId, $branchId)
            ->whereDate('journal_entries.entry_date', '>=', $range->from)
            ->whereDate('journal_entries.entry_date', '<=', $range->to)
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

    /** @return LazyCollection<int, \stdClass> */
    private function lineRows(Party $party, int $storeId, ReportDateRange $range, ?int $branchId): LazyCollection
    {
        return $this->periodQuery($party, $storeId, $range, $branchId)->lazy(500);
    }

    /**
     * @return array{opening_balance: string, debit_total: string, credit_total: string, closing_balance: string}
     */
    private function summary(Party $party, int $storeId, ReportDateRange $range, ?int $branchId): array
    {
        $base = $this->baseQuery($party, $storeId, $branchId);
        $openingDebit = (string) (clone $base)
            ->whereDate('journal_entries.entry_date', '<', $range->from)
            ->sum('journal_entry_lines.debit');
        $openingCredit = (string) (clone $base)
            ->whereDate('journal_entries.entry_date', '<', $range->from)
            ->sum('journal_entry_lines.credit');
        $period = (clone $base)
            ->whereDate('journal_entries.entry_date', '>=', $range->from)
            ->whereDate('journal_entries.entry_date', '<=', $range->to);
        $debit = Decimal::normalize((string) (clone $period)->sum('journal_entry_lines.debit'), 2);
        $credit = Decimal::normalize((string) (clone $period)->sum('journal_entry_lines.credit'), 2);
        $opening = Decimal::subtract($openingDebit, $openingCredit, 2);

        return [
            'opening_balance' => $opening,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'closing_balance' => Decimal::subtract(Decimal::add($opening, $debit, 2), $credit, 2),
        ];
    }

    /** @return array{id: int, code: string, name: string, kind: string} */
    private function party(Party $party): array
    {
        return [
            'id' => $party->id,
            'code' => $party->code,
            'name' => $party->name,
            'kind' => $party->customer ? 'customer' : 'supplier',
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function row(array $line, string $running): array
    {
        $running = Decimal::add($running, $line['debit'], 2);
        $running = Decimal::subtract($running, $line['credit'], 2);

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
            'balance' => $running,
        ];
    }
}
