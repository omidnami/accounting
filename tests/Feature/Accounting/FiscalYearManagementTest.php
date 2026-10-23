<?php

use App\Enums\FiscalYearStatus;
use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use Database\Seeders\StoreSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     store: Store,
 *     current: FiscalYear,
 *     current_year: int,
 *     service: FiscalYearSetupService
 * }
 */
function fiscalYearManagementContext(): array
{
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $service = app(FiscalYearSetupService::class);
    $currentYear = app(JalaliDateService::class)->currentYear();
    $current = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->first()
        ?? $service->setupJalaliYear($store, $currentYear);

    return [
        'store' => $store,
        'current' => $current,
        'current_year' => $currentYear,
        'service' => $service,
    ];
}

function fiscalYearManagementUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('creates a future fiscal year without replacing the current year', function () {
    ['store' => $store, 'current' => $current, 'current_year' => $currentYear, 'service' => $service] =
        fiscalYearManagementContext();

    $future = $service->setupJalaliYear($store, $currentYear + 1);

    expect($future->is_current)->toBeFalse()
        ->and($future->periods)->toHaveCount(12)
        ->and($future->documentSequences)->toHaveCount(9)
        ->and($current->fresh()->is_current)->toBeTrue();
});

it('switches the current fiscal year atomically', function () {
    ['store' => $store, 'current' => $current, 'current_year' => $currentYear, 'service' => $service] =
        fiscalYearManagementContext();
    $future = $service->setupJalaliYear($store, $currentYear + 1);

    $updated = $service->setCurrent($store, $future);

    expect($updated->is_current)->toBeTrue()
        ->and($current->fresh()->is_current)->toBeFalse()
        ->and(FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->count())->toBe(1);
});

it('rejects a closed fiscal year as the current year', function () {
    ['store' => $store, 'current_year' => $currentYear, 'service' => $service] =
        fiscalYearManagementContext();
    $future = $service->setupJalaliYear($store, $currentYear + 1);
    $future->forceFill(['status' => FiscalYearStatus::Closed])->save();

    expect(fn () => $service->setCurrent($store, $future))
        ->toThrow(DomainException::class);
});

it('renders fiscal years and creates a Persian-digit year from the interface', function () {
    ['store' => $store, 'current_year' => $currentYear] = fiscalYearManagementContext();
    $manager = fiscalYearManagementUser(UserRole::Manager);
    $nextYear = $currentYear + 1;
    $persianYear = strtr((string) $nextYear, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
    ]);

    $this->actingAs($manager)
        ->get(route('fiscal-years.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fiscal-years/Index')
            ->has('years', 1)
            ->has('years.0.periods', 12)
            ->where('years.0.is_current', true)
            ->where('summary.current_title', fn ($title) => is_string($title))
            ->where('suggested_year', $nextYear));

    $this->actingAs($manager)
        ->post(route('fiscal-years.store'), ['year' => $persianYear])
        ->assertRedirect(route('fiscal-years.index'));

    $future = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('title', (string) $nextYear)
        ->firstOrFail();

    expect($future->is_current)->toBeFalse()
        ->and($future->periods()->count())->toBe(12);
});

it('allows managers and forbids other roles from fiscal year management', function (UserRole $role) {
    fiscalYearManagementContext();
    $user = fiscalYearManagementUser($role);

    $this->actingAs($user)
        ->get(route('fiscal-years.index'))
        ->assertForbidden();
})->with([
    'accountant' => [UserRole::Accountant],
    'cashier' => [UserRole::Cashier],
    'warehouse' => [UserRole::Warehouse],
    'viewer' => [UserRole::Viewer],
]);

it('redirects guests and rejects duplicate fiscal years', function () {
    ['current_year' => $currentYear] = fiscalYearManagementContext();
    $manager = fiscalYearManagementUser(UserRole::Manager);

    $this->get(route('fiscal-years.index'))->assertRedirect(route('login'));

    $this->actingAs($manager)
        ->post(route('fiscal-years.store'), ['year' => $currentYear])
        ->assertSessionHasErrors('year');
});
