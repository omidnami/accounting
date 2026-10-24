<?php

namespace App\Services\Reporting;

use App\Models\JournalEntry;
use App\Support\ReportDateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

class JournalReportService
{
    /**
     * @return Collection<int, JournalEntry>
     */
    public function entries(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): Collection {
        $range = ReportDateRange::between($from, $to);

        return $this->query($storeId, $range->from, $range->to, $branchId)->get();
    }

    /**
     * @return LazyCollection<int, array{
     *     date: string,
     *     entry_number: string,
     *     entry_description: string,
     *     account_code: string,
     *     account_name: string,
     *     debit: string,
     *     credit: string,
     *     line_description: string|null
     * }>
     */
    public function lineRows(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): LazyCollection {
        $range = ReportDateRange::between($from, $to);

        return LazyCollection::make(function () use ($storeId, $range, $branchId) {
            foreach ($this->query($storeId, $range->from, $range->to, $branchId)->cursor() as $entry) {
                foreach ($entry->lines as $line) {
                    yield [
                        'date' => $entry->entry_date->toDateString(),
                        'entry_number' => $entry->entry_number,
                        'entry_description' => $entry->description,
                        'account_code' => $line->account->code,
                        'account_name' => $line->account->name,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'line_description' => $line->description,
                    ];
                }
            }
        });
    }

    /**
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function paginate(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
        int $perPage = 25,
        int $page = 1,
    ): LengthAwarePaginator {
        $range = ReportDateRange::between($from, $to);
        $perPage = max(1, min($perPage, 100));

        return $this->query($storeId, $range->from, $range->to, $branchId)
            ->paginate($perPage, ['*'], 'page', max(1, $page))
            ->withQueryString();
    }

    /** @return Builder<JournalEntry> */
    private function query(
        int $storeId,
        string $fromDate,
        string $toDate,
        ?int $branchId,
    ): Builder {
        return JournalEntry::query()
            ->posted()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('entry_date', [$fromDate, $toDate])
            ->with(['lines.account', 'branch', 'creator'])
            ->orderBy('entry_date')
            ->orderBy('entry_number');
    }
}
