<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use DomainException;

class AccountingPeriodLockService
{
    /**
     * @return array{branch: Branch, fiscal_year: FiscalYear, period: AccountingPeriod}
     */
    public function lockCurrentOpenPeriod(
        int $storeId,
        int $branchId,
        string $date,
    ): array {
        $fiscalYearId = FiscalYear::query()
            ->where('store_id', $storeId)
            ->where('is_current', true)
            ->where('status', FiscalYearStatus::Open)
            ->value('id');

        if (! $fiscalYearId) {
            throw new DomainException('سال مالی جاری باز پیدا نشد.');
        }

        return $this->lockOpenPeriod(
            $storeId,
            $branchId,
            (int) $fiscalYearId,
            $date,
        );
    }

    /**
     * @return array{branch: Branch, fiscal_year: FiscalYear, period: AccountingPeriod}
     */
    public function lockOpenPeriod(
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        string $date,
    ): array {
        $entryDate = CarbonImmutable::parse($date)->toDateString();
        $branch = Branch::query()
            ->whereKey($branchId)
            ->where('store_id', $storeId)
            ->first();

        if (! $branch || ! $branch->is_active) {
            throw new DomainException('شعبه انتخاب‌شده فعال و متعلق به فروشگاه نیست.');
        }

        $fiscalYear = FiscalYear::query()
            ->whereKey($fiscalYearId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        if (! $fiscalYear
            || $fiscalYear->status !== FiscalYearStatus::Open
            || $entryDate < $fiscalYear->starts_on->toDateString()
            || $entryDate > $fiscalYear->ends_on->toDateString()) {
            throw new DomainException('تاریخ سند باید داخل یک سال مالی باز باشد.');
        }

        $period = AccountingPeriod::query()
            ->where('store_id', $storeId)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', AccountingPeriodStatus::Open)
            ->whereDate('starts_on', '<=', $entryDate)
            ->whereDate('ends_on', '>=', $entryDate)
            ->lockForUpdate()
            ->first();

        if (! $period) {
            throw new DomainException('دوره مالی تاریخ سند باز نیست؛ ابتدا دوره را بازگشایی کنید.');
        }

        return [
            'branch' => $branch,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
        ];
    }
}
