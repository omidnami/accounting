<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function reportPageUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('redirects guests from financial reports', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

it('renders financial reports for a manager', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $manager = reportPageUser(UserRole::Manager);

    $this->actingAs($manager)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->has('branches')
            ->has('trialBalance.rows')
            ->has('incomeStatement.revenues')
            ->has('balanceSheet.total_assets')
            ->has('journal.data')
            ->where('filters.from', fn ($value) => is_string($value))
            ->where('trialBalance.balanced', true));
});

it('forbids cashiers from financial reports', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $cashier = reportPageUser(UserRole::Cashier);

    $this->actingAs($cashier)
        ->get(route('reports.index'))
        ->assertForbidden();
});

it('validates report date ranges', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $manager = reportPageUser(UserRole::Manager);

    $this->actingAs($manager)
        ->get(route('reports.index', [
            'from' => '2026-05-31',
            'to' => '2026-05-01',
        ]))
        ->assertSessionHasErrors('to');
});
