<?php

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Store;

function createAccountStore(): Store
{
    return Store::query()->create([
        'code' => 'ACCOUNT-TEST',
        'name' => 'فروشگاه تست حساب',
    ]);
}

it('connects subsidiary accounts to a same-type general account', function () {
    $store = createAccountStore();
    $general = Account::query()->create([
        'store_id' => $store->id,
        'code' => '1000',
        'name' => 'دارایی‌ها',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $cash = Account::query()->create([
        'store_id' => $store->id,
        'parent_id' => $general->id,
        'code' => '1101',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);

    expect($cash->parent->is($general))->toBeTrue()
        ->and($general->children)->toHaveCount(1)
        ->and($general->children->first()->is($cash))->toBeTrue()
        ->and($cash->isGeneral())->toBeFalse();
});

it('rejects a subsidiary account without a general parent', function () {
    $store = createAccountStore();

    Account::query()->create([
        'store_id' => $store->id,
        'code' => '1101',
        'name' => 'صندوق بدون والد',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);
})->throws(DomainException::class);

it('rejects an account hierarchy with mismatched account types', function () {
    $store = createAccountStore();
    $liabilities = Account::query()->create([
        'store_id' => $store->id,
        'code' => '2000',
        'name' => 'بدهی‌ها',
        'type' => AccountType::Liability,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Credit,
    ]);

    Account::query()->create([
        'store_id' => $store->id,
        'parent_id' => $liabilities->id,
        'code' => '1101',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);
})->throws(DomainException::class);

it('prevents deleting an account that has children', function () {
    $store = createAccountStore();
    $general = Account::query()->create([
        'store_id' => $store->id,
        'code' => '1000',
        'name' => 'دارایی‌ها',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);

    Account::query()->create([
        'store_id' => $store->id,
        'parent_id' => $general->id,
        'code' => '1101',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $general->delete();
})->throws(DomainException::class);

it('rejects linking a subsidiary account to another store', function () {
    $firstStore = createAccountStore();
    $secondStore = Store::query()->create([
        'code' => 'ACCOUNT-TEST-2',
        'name' => 'فروشگاه دوم',
    ]);
    $general = Account::query()->create([
        'store_id' => $firstStore->id,
        'code' => '1000',
        'name' => 'دارایی‌ها',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);

    Account::query()->create([
        'store_id' => $secondStore->id,
        'parent_id' => $general->id,
        'code' => '1101',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);
})->throws(DomainException::class);
