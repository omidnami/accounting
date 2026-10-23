<?php

use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function treasuryAccountUiContext(UserRole $role = UserRole::Manager): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $fiscalYear = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear(
            $store,
            app(JalaliDateService::class)->currentYear(),
        );
    $glAccount = Account::query()
        ->where('store_id', $store->id)
        ->where('code', '1102')
        ->firstOrFail();
    $cashGlAccount = Account::query()
        ->where('store_id', $store->id)
        ->where('code', '1101')
        ->firstOrFail();
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return compact(
        'store',
        'branch',
        'fiscalYear',
        'glAccount',
        'cashGlAccount',
        'user',
    );
}

/**
 * @return array<string, mixed>
 */
function treasuryAccountUiPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'code' => 'BANK-UI',
        'name' => 'بانک رابط',
        'type' => 'bank',
        'gl_account_id' => $context['glAccount']->id,
        'is_default' => true,
        'is_active' => true,
        'version' => 0,
    ], $overrides);
}

it('protects treasury account management by role', function () {
    $this->get(route('treasury-accounts.index'))
        ->assertRedirect(route('login'));
    treasuryAccountUiContext();
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();

    $this->actingAs($cashier)
        ->get(route('treasury-accounts.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->post(route('treasury-accounts.store'), [])
        ->assertForbidden();
});

it('allows managers and accountants to manage treasury accounts', function (UserRole $role) {
    $context = treasuryAccountUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('treasury-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury-accounts/Index')
            ->has('accounts', 3)
            ->has('summary'));

    $this->actingAs($context['user'])
        ->get(route('treasury-accounts.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury-accounts/Form')
            ->where('account', null)
            ->has('gl_accounts'));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('creates a treasury account and keeps one default per type', function () {
    $context = treasuryAccountUiContext();
    $seededBank = TreasuryAccount::query()
        ->where('type', TreasuryAccountType::Bank)
        ->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect(route('treasury-accounts.index'))
        ->assertSessionHasNoErrors();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();

    expect($account->store_id)->toBe($context['store']->id)
        ->and($account->branch_id)->toBe($context['branch']->id)
        ->and($account->type)->toBe(TreasuryAccountType::Bank)
        ->and($account->is_default)->toBeTrue()
        ->and($seededBank->refresh()->is_default)->toBeFalse();
});

it('shows active card accounts in receipt and payment forms', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context, [
            'code' => 'CARD-UI',
            'name' => 'کارت‌خوان رابط',
            'type' => 'card',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->get(route('receipts.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'treasury_accounts',
                fn ($accounts) => collect($accounts)->contains(
                    fn (array $account): bool => $account['type'] === 'card',
                ),
            ));

    $this->actingAs($context['user'])
        ->get(route('payments.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'treasury_accounts',
                fn ($accounts) => collect($accounts)->contains(
                    fn (array $account): bool => $account['type'] === 'card',
                ),
            ));
});

it('updates an unused treasury account and changes its default safely', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();

    $this->actingAs($context['user'])
        ->put(route('treasury-accounts.update', $account), treasuryAccountUiPayload($context, [
            'code' => 'BANK-UI-2',
            'name' => 'بانک ویرایش‌شده',
            'type' => 'cash',
            'gl_account_id' => $context['cashGlAccount']->id,
            'is_default' => false,
        ]))
        ->assertRedirect(route('treasury-accounts.index'))
        ->assertSessionHasNoErrors();

    expect($account->refresh()->code)->toBe('BANK-UI-2')
        ->and($account->name)->toBe('بانک ویرایش‌شده')
        ->and($account->type)->toBe(TreasuryAccountType::Cash)
        ->and($account->is_default)->toBeFalse();
});

it('blocks unsafe deactivation and identity changes for accounts with pending activity', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();
    Receipt::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'receipt_number' => 'TREASURY-UI-RECEIPT',
        'receipt_date' => $context['fiscalYear']->starts_on->toDateString(),
        'amount' => '100.00',
        'payment_method' => PaymentMethod::Cash,
        'treasury_account_id' => $account->id,
        'status' => TreasuryDocumentStatus::Pending,
    ]);

    $this->actingAs($context['user'])
        ->patch(route('treasury-accounts.status', $account), [
            'is_active' => false,
            'version' => $account->version,
        ])
        ->assertSessionHasErrors('treasury_account');

    $this->actingAs($context['user'])
        ->put(route('treasury-accounts.update', $account), treasuryAccountUiPayload($context, [
            'code' => 'BANK-CHANGED',
            'type' => 'cash',
            'is_default' => false,
        ]))
        ->assertSessionHasErrors('treasury_account');

    expect($account->refresh()->is_active)->toBeTrue()
        ->and($account->code)->toBe('BANK-UI')
        ->and($account->type)->toBe(TreasuryAccountType::Bank);
});

it('deactivates a safe account and clears its default flag', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();

    $this->actingAs($context['user'])
        ->patch(route('treasury-accounts.status', $account), [
            'is_active' => false,
            'version' => $account->version,
        ])
        ->assertRedirect(route('treasury-accounts.index'))
        ->assertSessionHasNoErrors();

    expect($account->refresh()->is_active)->toBeFalse()
        ->and($account->is_default)->toBeFalse();
});

it('rejects a general ledger account that is not an active asset subsidiary', function () {
    $context = treasuryAccountUiContext();
    $general = Account::query()
        ->where('store_id', $context['store']->id)
        ->where('code', '1000')
        ->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context, [
            'gl_account_id' => $general->id,
        ]))
        ->assertSessionHasErrors('treasury_account');

    expect(TreasuryAccount::query()->where('code', 'BANK-UI')->exists())->toBeFalse();
});

it('rejects a GL account that does not match the treasury account type', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context, [
            'type' => 'cash',
        ]))
        ->assertSessionHasErrors('treasury_account');

    expect(TreasuryAccount::query()->where('code', 'BANK-UI')->exists())->toBeFalse();
});

it('rejects a stale treasury account edit', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();

    $this->actingAs($context['user'])
        ->put(route('treasury-accounts.update', $account), treasuryAccountUiPayload($context, [
            'name' => 'نام جدید',
            'version' => 0,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->put(route('treasury-accounts.update', $account), treasuryAccountUiPayload($context, [
            'name' => 'نام قدیمی',
            'version' => 0,
        ]))
        ->assertSessionHasErrors('treasury_account');

    expect($account->refresh()->name)->toBe('نام جدید')
        ->and($account->version)->toBe(1);
});

it('allows an inactive account to be maintained after its GL account is deactivated', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();

    $this->actingAs($context['user'])
        ->patch(route('treasury-accounts.status', $account), [
            'is_active' => false,
            'version' => $account->version,
        ])
        ->assertSessionHasNoErrors();

    Account::query()->whereKey($context['glAccount']->id)->update(['is_active' => false]);
    $account->refresh();

    $this->actingAs($context['user'])
        ->put(route('treasury-accounts.update', $account), treasuryAccountUiPayload($context, [
            'name' => 'حساب آرشیوی',
            'is_default' => false,
            'is_active' => false,
            'version' => $account->version,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($account->refresh()->name)->toBe('حساب آرشیوی')
        ->and($account->is_active)->toBeFalse();
});

it('locks bank account identity after a check settlement used it', function () {
    $context = treasuryAccountUiContext();

    $this->actingAs($context['user'])
        ->post(route('treasury-accounts.store'), treasuryAccountUiPayload($context))
        ->assertRedirect();

    $account = TreasuryAccount::query()->where('code', 'BANK-UI')->firstOrFail();
    DB::table('checks')->insert([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'check_number' => 'TREASURY-UI-CHECK',
        'bank_name' => 'بانک تست',
        'amount' => '100.00',
        'due_date' => $context['fiscalYear']->starts_on->toDateString(),
        'type' => 'receivable',
        'status' => 'collected',
        'settlement_treasury_account_id' => $account->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($context['user'])
        ->put(route('treasury-accounts.update', $account), treasuryAccountUiPayload($context, [
            'code' => 'BANK-CHECK-USED',
            'is_default' => false,
        ]))
        ->assertSessionHasErrors('treasury_account');

    expect($account->refresh()->code)->toBe('BANK-UI');
});
