<?php

use App\Models\Customer;
use App\Models\Party;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows one party to have customer and supplier profiles', function () {
    $party = Party::query()->create([
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
    $party = Party::query()->create([
        'code' => 'PARTY-002',
        'name' => 'طرف‌حساب تکراری',
    ]);

    Customer::query()->create(['party_id' => $party->id]);
    Customer::query()->create(['party_id' => $party->id]);
})->throws(UniqueConstraintViolationException::class);
