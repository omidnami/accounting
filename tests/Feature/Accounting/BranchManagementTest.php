<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{store: Store, branch: Branch, warehouse: Warehouse, manager: User}
 */
function singleLocationContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch()->firstOrFail();
    $warehouse = $branch->warehouses()
        ->where('is_default', true)
        ->firstOrFail();
    $manager = User::factory()->create();
    $manager->forceFill([
        'role' => UserRole::Manager,
        'branch_id' => $branch->id,
    ])->save();

    return compact('store', 'branch', 'warehouse', 'manager');
}

it('does not expose branch management or switching endpoints', function () {
    $this->get(route('branches.index'))->assertRedirect(route('login'));

    $user = User::factory()->create();
    $user->forceFill(['role' => UserRole::Manager])->save();

    $this->actingAs($user)
        ->get(route('branches.index'))
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('branches.store'), [
            'code' => 'SECOND',
            'name' => 'شعبه دوم',
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('branches.switch'), ['branch_id' => 1])
        ->assertNotFound();
});

it('always uses the default branch despite stale session and employee assignment', function () {
    $context = singleLocationContext();
    $second = Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SECOND',
        'name' => 'شعبه قدیمی',
        'is_default' => false,
        'is_active' => true,
    ]);
    Warehouse::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $second->id,
        'code' => 'MAIN',
        'name' => 'انبار شعبه قدیمی',
        'is_default' => true,
        'is_active' => true,
    ]);
    $context['manager']->forceFill(['branch_id' => $second->id])->save();

    $this->actingAs($context['manager'])
        ->withSession(['active_branch_id' => $second->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('branch_context')
            ->where('auth.user.id', $context['manager']->id));

    $this->actingAs($context['manager'])
        ->withSession(['active_branch_id' => $second->id])
        ->get(route('sales.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Create')
            ->where('defaults.warehouse_id', $context['warehouse']->id)
            ->has('warehouses', 1)
            ->where('warehouses.0.id', $context['warehouse']->id));
});

it('creates and updates employees without assigning them to a branch', function () {
    $context = singleLocationContext();
    $second = Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SECOND',
        'name' => 'شعبه قدیمی',
        'is_default' => false,
        'is_active' => true,
    ]);

    $this->actingAs($context['manager'])
        ->post(route('users.store'), [
            'name' => 'صندوق‌دار فروشگاه',
            'email' => 'cashier@example.com',
            'role' => UserRole::Cashier->value,
            'branch_id' => $second->id,
            'password' => 'Secure-Pass-123',
            'password_confirmation' => 'Secure-Pass-123',
            'version' => 0,
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $employee = User::query()
        ->where('email', 'cashier@example.com')
        ->firstOrFail();

    expect($employee->branch_id)->toBeNull()
        ->and($employee->role)->toBe(UserRole::Cashier);

    $this->actingAs($context['manager'])
        ->put(route('users.update', $employee), [
            'name' => 'صندوق‌دار فروشگاه',
            'email' => 'cashier@example.com',
            'role' => UserRole::Cashier->value,
            'branch_id' => $second->id,
            'version' => $employee->version,
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    expect($employee->refresh()->branch_id)->toBeNull();
});
