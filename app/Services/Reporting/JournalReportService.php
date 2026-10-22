<?php

namespace App\Services\Reporting;

use App\Models\JournalEntry;
use App\Support\ReportDateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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
