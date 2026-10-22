<?php

namespace App\Services\Reporting;

use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

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
        $fromDate = CarbonImmutable::parse($from)->toDateString();
        $toDate = CarbonImmutable::parse($to)->toDateString();

        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('تاریخ شروع گزارش نمی‌تواند بعد از تاریخ پایان باشد.');
        }

        return JournalEntry::query()
            ->posted()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('entry_date', [$fromDate, $toDate])
            ->with(['lines.account', 'branch', 'creator'])
            ->orderBy('entry_date')
            ->orderBy('entry_number')
            ->get();
    }
}
