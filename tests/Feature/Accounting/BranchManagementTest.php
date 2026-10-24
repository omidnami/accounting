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
 * @return array{store: Store, branch: Branch, manager: User}
 */
function branchManagementContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch()->firstOrFail();
    $manager = User::factory()->create();
    $manager->forceFill([
        'role' => UserRole::Manager,
        'branch_id' => $branch->id,
    ])->save();

    return compact('store', 'branch', 'manager');
}

it('protects branch management by role', function () {
    $this->get(route('branches.index'))->assertRedirect(route('login'));
    $accountant = User::factory()->create();
    $accountant->forceFill(['role' => UserRole::Accountant])->save();

    $this->actingAs($accountant)
        ->get(route('branches.index'))
        ->assertForbidden();
});

it('creates a branch with its default warehouse', function () {
    $context = branchManagementContext();

    $this->actingAs($context['manager'])
        ->post(route('branches.store'), [
            'code' => 'branch-02',
            'name' => 'شعبه دوم',
            'phone' => '02112345678',
            'address' => 'تهران',
        ])
        ->assertRedirect(route('branches.index'))
        ->assertSessionHasNoErrors();

    $branch = Branch::query()->where('code', 'BRANCH-02')->firstOrFail();
    $warehouse = Warehouse::query()
        ->where('branch_id', $branch->id)
        ->where('is_default', true)
        ->firstOrFail();

    expect($branch->store_id)->toBe($context['store']->id)
        ->and($branch->is_default)->toBeFalse()
        ->and($branch->is_active)->toBeTrue()
        ->and($warehouse->store_id)->toBe($context['store']->id)
        ->and($warehouse->code)->toBe('MAIN');
});

it('switches the active branch and limits assigned employees', function () {
    $context = branchManagementContext();
    $second = Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'is_default' => false,
        'is_active' => true,
    ]);
    $secondWarehouse = Warehouse::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $second->id,
        'code' => 'MAIN',
        'name' => 'انبار شعبه دوم',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->actingAs($context['manager'])
        ->patch(route('branches.switch'), ['branch_id' => $second->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('active_branch_id', $second->id);

    $this->actingAs($context['manager'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('branch_context.active.id', $second->id)
            ->where('branch_context.can_switch', true));

    $this->actingAs($context['manager'])
        ->get(route('sales.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Create')
            ->where('defaults.warehouse_id', $secondWarehouse->id));

    $cashier = User::factory()->create();
    $cashier->forceFill([
        'role' => UserRole::Cashier,
        'branch_id' => $second->id,
    ])->save();

    $this->actingAs($cashier)
        ->patch(route('branches.switch'), ['branch_id' => $context['branch']->id])
        ->assertForbidden();

    $this->actingAs($cashier)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('branch_context.active.id', $second->id)
            ->where('branch_context.can_switch', false));
});

it('changes the default branch and prevents unsafe deactivation', function () {
    $context = branchManagementContext();
    $second = Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'is_default' => false,
        'is_active' => true,
    ]);

    $this->actingAs($context['manager'])
        ->patch(route('branches.default', $second))
        ->assertSessionHasNoErrors();

    expect($context['branch']->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue();

    $this->actingAs($context['manager'])
        ->patch(route('branches.status', $second), ['is_active' => false])
        ->assertSessionHasErrors('branch');

    expect($second->refresh()->is_active)->toBeTrue();
});

it('creates an employee assigned to a branch', function () {
    $context = branchManagementContext();
    $second = Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'is_default' => false,
        'is_active' => true,
    ]);

    $this->actingAs($context['manager'])
        ->post(route('users.store'), [
            'name' => 'صندوق‌دار شعبه دوم',
            'email' => 'cashier-branch@example.com',
            'role' => UserRole::Cashier->value,
            'branch_id' => $second->id,
            'password' => 'Secure-Pass-123',
            'password_confirmation' => 'Secure-Pass-123',
            'version' => 0,
        ])
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $employee = User::query()
        ->where('email', 'cashier-branch@example.com')
        ->firstOrFail();

    expect($employee->branch_id)->toBe($second->id)
        ->and($employee->role)->toBe(UserRole::Cashier);
});
