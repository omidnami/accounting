<?php

use App\Models\Account;
use App\Models\Store;
use Database\Seeders\AccountSeeder;

it('seeds the standard chart of accounts idempotently', function () {
    $this->seed(AccountSeeder::class);
    $firstCount = Account::query()->count();

    $this->seed(AccountSeeder::class);

    expect($firstCount)->toBe(26)
        ->and(Account::query()->count())->toBe($firstCount)
        ->and(Store::query()->where('code', 'MAIN')->firstOrFail()->defaultBranch)->not->toBeNull()
        ->and(Account::query()->where('code', '1101')->first()->store_id)
        ->toBe(Store::query()->where('code', 'MAIN')->value('id'))
        ->and(Account::query()->where('code', '1101')->first()->parent->code)->toBe('1000')
        ->and(Account::query()->where('code', '2102')->first()->parent->code)->toBe('2000')
        ->and(Account::query()->where('code', '5101')->first()->parent->code)->toBe('5000');
});
