<?php

namespace App\Services\Accounting\Contracts;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Store;

interface PeriodCloseBlocker
{
    public function count(
        Store $store,
        FiscalYear $fiscalYear,
        ?AccountingPeriod $period = null,
    ): int;
}
