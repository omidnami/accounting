<?php

namespace App\Services\Reporting;

use App\Models\JournalEntryLine;
use App\Models\Party;
use App\Support\Decimal;
use App\Support\PaginationPayload;
use App\Support\ReportDateRange;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
        if ((int) $party->store_id !== $storeId) {
            throw new DomainException('طرف‌حساب متعلق به این فروشگاه نیست.');
        }

        $range = ReportDateRange::between($from, $to);
        $base = fn () => JournalEntryLine::query()
            ->where('party_id', $party->id)
            ->whereHas('journalEntry', function ($query) use ($storeId, $branchId): void {
                $query->posted()
                    ->where('store_id', $storeId)
                    ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));
            });
        $openingDebit = (string) $base()
            ->whereHas('journalEntry', fn ($query) => $query->whereDate('entry_date', '<', $range->from))
            ->sum('debit');
        $openingCredit = (string) $base()
            ->whereHas('journalEntry', fn ($query) => $query->whereDate('entry_date', '<', $range->from))
            ->sum('credit');
        $lines = $base()
            ->whereHas('journalEntry', fn ($query) => $query->whereDate('entry_date', '>=', $range->from)
                ->whereDate('entry_date', '<=', $range->to))
            ->with(['journalEntry', 'account'])
            ->get()
            ->sortBy([
                fn (JournalEntryLine $line) => $line->journalEntry->entry_date->toDateString(),
                fn (JournalEntryLine $line) => $line->journalEntry->entry_number,
                fn (JournalEntryLine $line) => $line->sort_order,
            ])
            ->values();
        $running = Decimal::subtract($openingDebit, $openingCredit, 2);
        $debitTotal = '0.00';
        $creditTotal = '0.00';
        $rows = $lines->map(function (JournalEntryLine $line) use (
            &$running,
            &$debitTotal,
            &$creditTotal,
        ): array {
            $debitTotal = Decimal::add($debitTotal, $line->debit, 2);
            $creditTotal = Decimal::add($creditTotal, $line->credit, 2);
            $running = Decimal::add($running, $line->debit, 2);
            $running = Decimal::subtract($running, $line->credit, 2);

            return [
                'entry_id' => $line->journalEntry->id,
                'entry_number' => $line->journalEntry->entry_number,
                'date' => $line->journalEntry->entry_date->toDateString(),
                'description' => $line->description ?: $line->journalEntry->description,
                'account_code' => $line->account->code,
                'account_name' => $line->account->name,
                'debit' => Decimal::normalize($line->debit, 2),
                'credit' => Decimal::normalize($line->credit, 2),
                'balance' => $running,
            ];
        })->values();

        return [
            'party' => [
                'id' => $party->id,
                'code' => $party->code,
                'name' => $party->name,
                'kind' => $party->customer ? 'customer' : 'supplier',
            ],
            'opening_balance' => Decimal::subtract($openingDebit, $openingCredit, 2),
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'closing_balance' => $running,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pageReport(
        Party $party,
        int $storeId,
        string $from,
        string $to,
        ?int $branchId,
        int $page,
    ): array {
        $build = fn (): array => $this->report(
            $party,
            $storeId,
            $from,
            $to,
            $branchId,
        );
        $data = app()->environment('testing')
            ? $build()
            : Cache::remember(
                "party-statement:{$storeId}:{$branchId}:{$party->id}:{$from}:{$to}",
                now()->addMinute(),
                $build,
            );

        return [
            ...$data,
            'rows' => PaginationPayload::from($data['rows'], $page),
        ];
    }
}
