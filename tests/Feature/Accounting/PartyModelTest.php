<?php

use App\Models\Customer;
use App\Models\Party;
use App\Models\Store;
use App\Models\Supplier;
use Database\Seeders\StoreSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows one party to have customer and supplier profiles', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'PARTY-001',
        'name' => 'طرف‌حساب دو نقش',
        'national_id' => '1234567890',
    ]);

    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 10_000_000,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
        'payment_terms' => '30 روز',
    ]);

    expect($party->customer->is($customer))->toBeTrue()
        ->and($party->supplier->is($supplier))->toBeTrue()
        ->and($customer->party->is($party))->toBeTrue()
        ->and($supplier->party->is($party))->toBeTrue();
});

it('prevents duplicate customer or supplier profiles for one party', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'PARTY-002',
        'name' => 'طرف‌حساب تکراری',
    ]);

    Customer::query()->create(['party_id' => $party->id]);
    Customer::query()->create(['party_id' => $party->id]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same party code in different stores', function () {
    app(StoreSeeder::class)->run();
    $mainStore = Store::query()->where('code', 'MAIN')->firstOrFail();
    $otherStore = Store::query()->create([
        'code' => 'STORE-2',
        'name' => 'فروشگاه دوم',
    ]);

    Party::query()->create([
        'store_id' => $mainStore->id,
        'code' => 'SHARED-CODE',
        'name' => 'طرف‌حساب فروشگاه اول',
    ]);
    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'SHARED-CODE',
        'name' => 'طرف‌حساب فروشگاه دوم',
    ]);

    expect($otherParty->store->is($otherStore))->toBeTrue();
});

it('rejects duplicate party codes inside one store', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();

    Party::query()->create([
        'store_id' => $store->id,
        'code' => 'DUPLICATE',
        'name' => 'اول',
    ]);
    Party::query()->create([
        'store_id' => $store->id,
        'code' => 'DUPLICATE',
        'name' => 'دوم',
    ]);
})->throws(UniqueConstraintViolationException::class);
