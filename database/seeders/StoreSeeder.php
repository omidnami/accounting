<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $store = Store::query()->updateOrCreate(
                ['code' => 'MAIN'],
                [
                    'name' => 'فروشگاه اصلی',
                    'currency' => 'IRR',
                    'timezone' => 'Asia/Tehran',
                    'is_active' => true,
                ],
            );

            Branch::query()->updateOrCreate(
                [
                    'store_id' => $store->id,
                    'code' => 'MAIN',
                ],
                [
                    'name' => 'شعبه اصلی',
                    'is_default' => true,
                    'is_active' => true,
                ],
            );
        });
    }
}
