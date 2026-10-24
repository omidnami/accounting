<?php

use App\Models\Account;
use App\Models\Store;
use Database\Seeders\AccountSeeder;

it('seeds the standard chart of accounts idempotently', function () {
    $this->seed(AccountSeeder::class);
    $firstCount = Account::query()->count();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $cash = Account::query()->where('code', '1101')->firstOrFail();
    $store->update(['name' => 'فروشگاه سفارشی']);
    $cash->update(['name' => 'صندوق سفارشی', 'is_active' => false]);

    $this->seed(AccountSeeder::class);

    expect($firstCount)->toBe(30)
        ->and(Account::query()->count())->toBe($firstCount)
        ->and(Store::query()->where('code', 'MAIN')->firstOrFail()->name)->toBe('فروشگاه سفارشی')
        ->and(Store::query()->where('code', 'MAIN')->firstOrFail()->defaultBranch)->not->toBeNull()
        ->and(Account::query()->where('code', '1101')->first()->store_id)
        ->toBe($store->id)
        ->and($cash->fresh()->name)->toBe('صندوق سفارشی')
        ->and($cash->fresh()->is_active)->toBeFalse()
        ->and($cash->parent->code)->toBe('1000')
        ->and(Account::query()->where('code', '2102')->first()->parent->code)->toBe('2000')
        ->and(Account::query()->where('code', '5101')->first()->parent->code)->toBe('5000');
});
