<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\Contracts\PeriodCloseBlocker;

class PeriodCloseBlockerRegistry
{
    /** @var array<int, PeriodCloseBlocker> */
    private array $blockers = [];

    public function register(PeriodCloseBlocker $blocker): void
    {
        $this->blockers[] = $blocker;
    }

    public function count(Store $store, FiscalYear $fiscalYear, ?AccountingPeriod $period = null): int
    {
        return array_sum(array_map(
            fn (PeriodCloseBlocker $blocker): int => $blocker->count($store, $fiscalYear, $period),
            $this->blockers,
        ));
    }
}
