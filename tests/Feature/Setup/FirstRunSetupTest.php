<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;

it('creates the first manager, store, branch, warehouse, fiscal year and chart atomically', function () {
    $this->get(route('setup.index'))->assertOk();

    $this->post(route('setup.store'), [
        'store_name' => 'فروشگاه راه‌اندازی',
        'admin_name' => 'مدیر اول',
        'email' => 'owner@example.com',
        'password' => 'Secure-Pass-123',
        'password_confirmation' => 'Secure-Pass-123',
        'timezone' => 'Asia/Tehran',
        'currency' => 'IRR',
    ])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

    $user = User::query()->firstOrFail();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    expect($user->role)->toBe(UserRole::Manager)
        ->and($user->is_active)->toBeTrue()
        ->and($this->isAuthenticated())->toBeTrue()
        ->and($store->name)->toBe('فروشگاه راه‌اندازی')
        ->and(Branch::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and(Warehouse::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and($store->fiscalYears()->count())->toBe(1)
        ->and(Account::query()->where('store_id', $store->id)->count())->toBe(31);

    $this->get(route('setup.index'))->assertNotFound();
});

it('requires the configured setup token before the first user exists', function () {
    config(['app.setup_token' => 'secret-setup-token']);

    $this->get(route('setup.index'))->assertForbidden();
    $this->get(route('setup.index', ['token' => 'secret-setup-token']))->assertOk();
});
