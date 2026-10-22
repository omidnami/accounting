<?php

namespace Database\Seeders;

use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use Illuminate\Database\Seeder;

class AccountingSetupSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $year = app(JalaliDateService::class)->currentYear();

        if (FiscalYear::query()->where('store_id', $store->id)->where('title', (string) $year)->exists()) {
            return;
        }

        app(FiscalYearSetupService::class)->setupJalaliYear($store, $year);
    }
}
