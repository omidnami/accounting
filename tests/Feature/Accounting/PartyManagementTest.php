<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\StoreSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function partyManagementUser(UserRole $role): User
{
    app(StoreSeeder::class)->run();
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

function mainStore(): Store
{
    return Store::query()->where('code', 'MAIN')->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function partyPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'CUST-1001',
        'entity_type' => 'individual',
        'name' => 'مشتری نمونه',
        'phone' => '09121234567',
        'email' => 'customer@example.com',
        'national_id' => '1234567890',
        'tax_id' => null,
        'address' => 'تهران، خیابان نمونه',
        'is_active' => true,
        'roles' => ['customer'],
        'customer' => [
            'credit_limit' => '50000000',
            'payment_terms' => '۳۰ روزه',
            'sales_notes' => 'مشتری وفادار',
        ],
        'supplier' => [
            'payment_terms' => null,
            'lead_time_days' => null,
            'purchasing_notes' => null,
        ],
    ], $overrides);
}

it('redirects guests and forbids unauthorized roles from party management', function () {
    $this->get(route('parties.index'))->assertRedirect(route('login'));

    $cashier = partyManagementUser(UserRole::Cashier);

    $this->actingAs($cashier)
        ->get(route('parties.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->post(route('parties.store'), partyPayload())
        ->assertForbidden();
});

it('renders the party index for managers with store-scoped summary data', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CUST-2001',
        'name' => 'شرکت نمونه',
        'is_active' => true,
    ]);
    Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);
    Supplier::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);

    $otherStore = Store::query()->create([
        'code' => 'OTHER',
        'name' => 'فروشگاه دیگر',
        'is_active' => true,
    ]);
    Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'HIDDEN-1',
        'name' => 'نباید نمایش داده شود',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('parties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('parties/Index')
            ->where('summary.total', 1)
            ->where('summary.active', 1)
            ->where('summary.customers', 1)
            ->where('summary.suppliers', 1)
            ->has('parties.data', 1)
            ->where('parties.data.0.code', 'CUST-2001')
            ->where('parties.data.0.roles', ['customer', 'supplier']));
});

it('allows accountants into party management', function () {
    $accountant = partyManagementUser(UserRole::Accountant);

    $this->actingAs($accountant)
        ->get(route('parties.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('parties/Form')
            ->where('party', null));
});

it('filters parties by search, role and status', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    $customer = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'ALI-10',
        'name' => 'علی رضایی',
        'phone' => '09120000001',
        'is_active' => true,
    ]);
    Customer::query()->create([
        'party_id' => $customer->id,
        'is_active' => true,
    ]);
    $supplier = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'SUP-20',
        'name' => 'تأمین‌کننده پارس',
        'is_active' => false,
    ]);
    Supplier::query()->create([
        'party_id' => $supplier->id,
        'is_active' => false,
    ]);

    $this->actingAs($manager)
        ->get(route('parties.index', [
            'search' => 'علی',
            'role' => 'customer',
            'status' => 'active',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('parties.data', 1)
            ->where('parties.data.0.code', 'ALI-10')
            ->where('filters.search', 'علی')
            ->where('filters.role', 'customer')
            ->where('filters.status', 'active'));
});

it('normalizes Persian and Arabic digits in party search', function (string $search) {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    Party::query()->create([
        'store_id' => $store->id,
        'code' => 'DIGITS-10',
        'name' => 'طرف‌حساب ارقام',
        'phone' => '09121234567',
        'national_id' => '1234567890',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('parties.index', ['search' => $search]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('parties.data', 1)
            ->where('parties.data.0.code', 'DIGITS-10'));
})->with([
    'Persian digits' => ['۰۹۱۲۱۲۳۴۵۶۷'],
    'Arabic digits' => ['٠٩١٢١٢٣٤٥٦٧'],
]);

it('paginates party results with fifteen records per page', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    $partyIds = [];

    foreach (range(1, 16) as $index) {
        $partyIds[] = Party::query()->create([
            'store_id' => $store->id,
            'code' => sprintf('PARTY-%02d', $index),
            'name' => 'نام یکسان برای کنترل ترتیب',
            'is_active' => true,
        ])->id;
    }

    $this->actingAs($manager)
        ->get(route('parties.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('parties.data', 1)
            ->where('parties.current_page', 2)
            ->where('parties.total', 16)
            ->where('parties.data.0.id', $partyIds[15]));
});

it('creates a customer-only party', function () {
    $manager = partyManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('parties.store'), partyPayload())
        ->assertRedirect(route('parties.index'));

    $party = Party::query()->where('code', 'CUST-1001')->firstOrFail();

    expect($party->store_id)->toBe(mainStore()->id)
        ->and($party->customer)->not->toBeNull()
        ->and($party->customer->credit_limit)->toBe('50000000.00')
        ->and($party->supplier)->toBeNull();
});

it('creates a supplier-only party', function () {
    $manager = partyManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('parties.store'), partyPayload([
            'code' => 'SUP-1001',
            'name' => 'تأمین‌کننده نمونه',
            'roles' => ['supplier'],
            'customer' => [
                'credit_limit' => '-1',
            ],
            'supplier' => [
                'payment_terms' => '۴۵ روزه',
                'lead_time_days' => 7,
                'purchasing_notes' => 'تأمین‌کننده اصلی',
            ],
        ]))
        ->assertRedirect(route('parties.index'));

    $party = Party::query()->where('code', 'SUP-1001')->firstOrFail();

    expect($party->supplier)->not->toBeNull()
        ->and($party->supplier->lead_time_days)->toBe(7)
        ->and($party->customer)->toBeNull();
});

it('creates one identity with customer and supplier roles together', function () {
    $manager = partyManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('parties.store'), partyPayload([
            'code' => 'BOTH-1001',
            'name' => 'طرف‌حساب دو نقش',
            'roles' => ['customer', 'supplier'],
            'supplier' => [
                'payment_terms' => '۳۰ روزه',
                'lead_time_days' => 5,
                'purchasing_notes' => 'خرید و فروش متقابل',
            ],
        ]))
        ->assertRedirect(route('parties.index'));

    $party = Party::query()->where('code', 'BOTH-1001')->firstOrFail();

    expect($party->customer)->not->toBeNull()
        ->and($party->supplier)->not->toBeNull()
        ->and(Customer::query()->where('party_id', $party->id)->count())->toBe(1)
        ->and(Supplier::query()->where('party_id', $party->id)->count())->toBe(1);
});

it('updates a profile while preserving party and profile identity', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'KEEP-10',
        'name' => 'هویت پایدار',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 1000,
        'is_active' => true,
    ]);
    $partyId = $party->id;
    $customerId = $customer->id;

    $this->actingAs($manager)
        ->put(route('parties.update', $party), partyPayload([
            'code' => 'KEEP-10',
            'name' => 'هویت پایدار',
            'version' => 0,
            'customer' => [
                'credit_limit' => '2500000',
                'payment_terms' => '۶۰ روزه',
                'sales_notes' => 'پروفایل به‌روزشده',
            ],
        ]))
        ->assertRedirect(route('parties.index'));

    $party->refresh();
    $customer->refresh();

    expect($party->id)->toBe($partyId)
        ->and($customer->id)->toBe($customerId)
        ->and($customer->party_id)->toBe($party->id)
        ->and($customer->credit_limit)->toBe('2500000.00');
});

it('deactivates a removed role without deleting its financial profile', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'ROLE-10',
        'name' => 'طرف‌حساب دو نقش',
        'is_active' => true,
    ]);
    Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->put(route('parties.update', $party), partyPayload([
            'code' => 'ROLE-10',
            'name' => 'طرف‌حساب دو نقش',
            'version' => 0,
            'roles' => ['supplier'],
        ]))
        ->assertRedirect(route('parties.index'));

    $supplier->refresh();

    expect($supplier->is_active)->toBeTrue()
        ->and(Customer::query()->where('party_id', $party->id)->count())->toBe(1)
        ->and(Customer::query()->where('party_id', $party->id)->value('is_active'))->toBeFalse();
});

it('enforces store-scoped party codes and rejects invalid role selections', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $otherStore = Store::query()->create([
        'code' => 'SECOND',
        'name' => 'فروشگاه دوم',
        'is_active' => true,
    ]);
    Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'SHARED-100',
        'name' => 'کد مشترک در فروشگاه دیگر',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->post(route('parties.store'), partyPayload([
            'code' => 'SHARED-100',
            'name' => 'طرف‌حساب فروشگاه اصلی',
        ]))
        ->assertRedirect(route('parties.index'));

    expect(Party::query()->where('code', 'SHARED-100')->count())->toBe(2);

    $this->actingAs($manager)
        ->post(route('parties.store'), partyPayload([
            'code' => 'SHARED-100',
            'name' => 'کد تکراری',
        ]))
        ->assertSessionHasErrors('code');

    $this->actingAs($manager)
        ->post(route('parties.store'), partyPayload([
            'code' => 'NO-ROLE',
            'name' => 'بدون نقش',
            'roles' => [],
        ]))
        ->assertSessionHasErrors('roles');
});

it('rejects stale party updates and increments the lock version', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $store = mainStore();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'VERSION-10',
        'name' => 'نسخه اولیه',
        'is_active' => true,
    ]);
    Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);
    $payload = partyPayload([
        'code' => 'VERSION-10',
        'name' => 'نسخه به‌روزشده',
        'version' => 0,
    ]);

    $this->actingAs($manager)
        ->put(route('parties.update', $party), $payload)
        ->assertRedirect(route('parties.index'));

    expect($party->refresh()->version)->toBe(1);

    $this->actingAs($manager)
        ->put(route('parties.update', $party), $payload)
        ->assertSessionHasErrors('party');

    expect($party->refresh()->version)->toBe(1)
        ->and($party->name)->toBe('نسخه به‌روزشده');
});

it('does not allow editing a party from another store', function () {
    $manager = partyManagementUser(UserRole::Manager);
    $otherStore = Store::query()->create([
        'code' => 'PRIVATE',
        'name' => 'فروشگاه خصوصی',
        'is_active' => true,
    ]);
    $party = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'PRIVATE-1',
        'name' => 'طرف‌حساب فروشگاه دیگر',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('parties.edit', $party))
        ->assertNotFound();
});
