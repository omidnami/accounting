<?php

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Facades\DB;

function accountManagementManager(): User
{
    $user = User::factory()->create(['role' => UserRole::Manager]);

    return $user;
}

it('allows financial roles to view accounts but only managers to manage them', function () {
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    $manager = accountManagementManager();
    $accountant = User::factory()->create(['role' => UserRole::Accountant]);

    $this->actingAs($accountant)->get(route('accounts.index'))->assertOk();
    $this->actingAs($accountant)->get(route('accounts.create'))->assertForbidden();
    $this->actingAs($manager)->get(route('accounts.create'))->assertOk();
});

it('creates a valid subsidiary account and enforces store-scoped codes', function () {
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    $manager = accountManagementManager();
    $parent = Account::query()->where('code', '1000')->firstOrFail();

    $this->actingAs($manager)->post(route('accounts.store'), [
        'code' => '1099',
        'name' => 'حساب آزمایشی',
        'type' => 'asset',
        'level' => 'subsidiary',
        'normal_balance' => 'debit',
        'parent_id' => $parent->id,
        'is_active' => true,
    ])->assertRedirect(route('accounts.index'))->assertSessionHasNoErrors();

    expect(Account::query()->where('code', '1099')->firstOrFail()->parent_id)
        ->toBe($parent->id);

    $this->actingAs($manager)->post(route('accounts.store'), [
        'code' => '1099',
        'name' => 'کد تکراری',
        'type' => 'asset',
        'level' => 'subsidiary',
        'normal_balance' => 'debit',
        'parent_id' => $parent->id,
    ])->assertSessionHasErrors('code');

    $secondStore = Store::query()->create(['code' => 'SECOND', 'name' => 'Second']);
    $secondParent = Account::query()->create([
        'store_id' => $secondStore->id,
        'code' => '1000',
        'name' => 'Assets',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);
    $secondAccount = Account::query()->create([
        'store_id' => $secondStore->id,
        'parent_id' => $secondParent->id,
        'code' => '1099',
        'name' => 'Same code, different store',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);

    expect($secondAccount->store_id)->toBe($secondStore->id);
});

it('validates account prefixes and natural balance', function () {
    $store = Store::query()->create(['code' => 'VALIDATION', 'name' => 'Validation']);

    Account::query()->create([
        'store_id' => $store->id,
        'code' => '4199',
        'name' => 'Wrong balance',
        'type' => AccountType::Revenue,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);
})->throws(DomainException::class, 'سازگار نیست');

it('locks account structure after the account is used in a journal line', function () {
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $period = $year->periods()->firstOrFail();
    $account = Account::query()->where('code', '1101')->firstOrFail();
    $entryId = DB::table('journal_entries')->insertGetId([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $year->id,
        'accounting_period_id' => $period->id,
        'entry_number' => 'FREEZE-TEST',
        'entry_date' => $period->starts_on->toDateString(),
        'description' => 'Freeze test',
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $entryId,
        'account_id' => $account->id,
        'debit' => '1.00',
        'credit' => '0.00',
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $account->update(['code' => '1102']);
})->throws(DomainException::class, 'ساختار حساب پس از استفاده');
