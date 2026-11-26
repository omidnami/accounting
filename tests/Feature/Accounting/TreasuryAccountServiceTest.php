<?php

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Services\Treasury\TreasuryAccountService;
use Illuminate\Support\Facades\DB;

/**
 * @return array{cash: Account, bank: Account}
 */
function treasuryAccountServiceAccounts(Store $store): array
{
    $assets = Account::query()->create([
        'store_id' => $store->id,
        'code' => '1000',
        'name' => 'دارایی‌ها',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
        'is_active' => true,
    ]);
    $cash = Account::query()->create([
        'store_id' => $store->id,
        'parent_id' => $assets->id,
        'code' => '1101',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
        'is_active' => true,
    ]);
    $bank = Account::query()->create([
        'store_id' => $store->id,
        'parent_id' => $assets->id,
        'code' => '1102',
        'name' => 'بانک',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
        'is_active' => true,
    ]);

    return compact('cash', 'bank');
}

/**
 * @return array<string, mixed>
 */
function treasuryAccountServiceContext(string $suffix = 'MAIN'): array
{
    $store = Store::query()->create([
        'code' => "TREASURY-SERVICE-{$suffix}",
        'name' => 'فروشگاه حساب خزانه',
        'is_active' => true,
    ]);
    $branch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'MAIN',
        'name' => 'شعبه اصلی',
        'is_default' => true,
        'is_active' => true,
    ]);
    $secondBranch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'is_active' => true,
    ]);
    $fiscalYear = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => '2025',
        'starts_on' => '2025-01-01',
        'ends_on' => '2025-12-31',
        'is_current' => false,
    ]);

    return [
        'store' => $store,
        'branch' => $branch,
        'secondBranch' => $secondBranch,
        'fiscalYear' => $fiscalYear,
        ...treasuryAccountServiceAccounts($store),
    ];
}

/**
 * @return array<string, mixed>
 */
function treasuryAccountServicePayload(array $context, array $overrides = []): array
{
    return array_replace([
        'code' => ' bank-main ',
        'name' => ' بانک اصلی ',
        'type' => TreasuryAccountType::Bank->value,
        'gl_account_id' => $context['bank']->id,
        'is_default' => true,
        'is_active' => true,
        'version' => 0,
    ], $overrides);
}

function treasuryAccountServicePayment(
    array $context,
    TreasuryAccount $treasury,
    string $number,
    TreasuryDocumentStatus $status = TreasuryDocumentStatus::Pending,
): Payment {
    $payment = Payment::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'payment_number' => $number,
        'payment_date' => '2025-06-15',
        'amount' => '100.00',
        'payment_method' => PaymentMethod::Bank,
        'treasury_account_id' => $treasury->id,
    ]);

    if ($status !== TreasuryDocumentStatus::Pending) {
        DB::table('payments')->where('id', $payment->id)->update(['status' => $status->value]);
    }

    return $payment->refresh();
}

it('normalizes created accounts and keeps defaults scoped by branch and type', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);

    $first = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context));
    $second = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'code' => ' bank-second ',
        'name' => ' بانک دوم ',
    ]));
    $cash = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'code' => ' cash-main ',
        'name' => ' صندوق اصلی ',
        'type' => TreasuryAccountType::Cash->value,
        'gl_account_id' => $context['cash']->id,
    ]));
    $otherBranchBank = $service->create($context['store'], $context['secondBranch'], treasuryAccountServicePayload($context, [
        'code' => 'bank-other-branch',
    ]));
    $inactive = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'code' => 'bank-inactive',
        'is_active' => false,
    ]));

    expect($first->store_id)->toBe($context['store']->id)
        ->and($first->branch_id)->toBe($context['branch']->id)
        ->and($first->code)->toBe('BANK-MAIN')
        ->and($first->name)->toBe('بانک اصلی')
        ->and($first->type)->toBe(TreasuryAccountType::Bank)
        ->and($first->gl_account_id)->toBe($context['bank']->id)
        ->and($first->fresh()->version)->toBe(0)
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and($first->refresh()->default_slot)->toBeNull()
        ->and($second->is_default)->toBeTrue()
        ->and($second->default_slot)->toBe(TreasuryAccountType::Bank->value)
        ->and($cash->is_default)->toBeTrue()
        ->and($cash->default_slot)->toBe(TreasuryAccountType::Cash->value)
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($otherBranchBank->is_default)->toBeTrue()
        ->and($otherBranchBank->branch_id)->toBe($context['secondBranch']->id)
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->is_default)->toBeFalse()
        ->and($inactive->default_slot)->toBeNull();
});

it('updates unused identity moves the default and rejects stale versions without partial writes', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $first = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context));
    $second = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'code' => 'BANK-SECOND',
        'is_default' => false,
    ]));

    $updated = $service->update($second, treasuryAccountServicePayload($context, [
        'code' => ' bank-updated ',
        'name' => ' بانک ویرایش‌شده ',
        'is_default' => true,
        'version' => 0,
    ]));

    expect($updated->code)->toBe('BANK-UPDATED')
        ->and($updated->name)->toBe('بانک ویرایش‌شده')
        ->and($updated->is_default)->toBeTrue()
        ->and($updated->default_slot)->toBe(TreasuryAccountType::Bank->value)
        ->and($updated->version)->toBe(1)
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and($first->refresh()->default_slot)->toBeNull();

    expect(fn () => $service->update($second, treasuryAccountServicePayload($context, [
        'code' => 'BANK-STALE',
        'name' => 'نام کهنه',
        'version' => 0,
    ])))->toThrow(DomainException::class, 'هم‌زمان')
        ->and($second->refresh()->code)->toBe('BANK-UPDATED')
        ->and($second->refresh()->name)->toBe('بانک ویرایش‌شده')
        ->and($second->refresh()->version)->toBe(1)
        ->and($second->refresh()->is_default)->toBeTrue();

    expect(fn () => $service->setActive($second, false, 0))
        ->toThrow(DomainException::class, 'هم‌زمان')
        ->and($second->refresh()->is_active)->toBeTrue()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($second->refresh()->version)->toBe(1);

    $changedType = $service->update($second->fresh(), treasuryAccountServicePayload($context, [
        'code' => 'BANK-UPDATED',
        'name' => 'حساب تبدیل‌شده',
        'type' => TreasuryAccountType::Cash->value,
        'gl_account_id' => $context['cash']->id,
        'is_default' => false,
        'version' => 1,
    ]));

    expect($changedType->type)->toBe(TreasuryAccountType::Cash)
        ->and($changedType->gl_account_id)->toBe($context['cash']->id)
        ->and($changedType->version)->toBe(2)
        ->and($changedType->is_default)->toBeFalse();
});

it('rolls back default clearing when duplicate normalized codes are rejected', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $existing = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context));

    expect(fn () => $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'code' => '  BANK-MAIN  ',
        'name' => 'کد تکراری',
    ])))->toThrow(DomainException::class, 'این کد حساب خزانه در شعبه جاری قبلاً استفاده شده است.')
        ->and(TreasuryAccount::query()->where('branch_id', $context['branch']->id)->count())->toBe(1)
        ->and($existing->refresh()->code)->toBe('BANK-MAIN')
        ->and($existing->refresh()->is_default)->toBeTrue()
        ->and($existing->refresh()->default_slot)->toBe(TreasuryAccountType::Bank->value)
        ->and($existing->refresh()->version)->toBe(0);
});

it('rejects cross store branches and general ledger accounts without creating rows', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $other = treasuryAccountServiceContext('OTHER');

    expect(fn () => $service->create($context['store'], $other['branch'], treasuryAccountServicePayload($context)))
        ->toThrow(DomainException::class, 'شعبه حساب خزانه باید فعال و متعلق به فروشگاه باشد.')
        ->and(TreasuryAccount::query()->where('code', 'BANK-MAIN')->exists())->toBeFalse();

    expect(fn () => $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'gl_account_id' => $other['bank']->id,
    ])))->toThrow(DomainException::class, 'حساب دفتر کل باید معین')
        ->and(TreasuryAccount::query()->where('code', 'BANK-MAIN')->exists())->toBeFalse();

    $context['secondBranch']->update(['is_active' => false]);

    expect(fn () => $service->create($context['store'], $context['secondBranch'], treasuryAccountServicePayload($context, [
        'code' => 'BANK-INACTIVE-BRANCH',
    ])))->toThrow(DomainException::class, 'شعبه حساب خزانه باید فعال و متعلق به فروشگاه باشد.')
        ->and(TreasuryAccount::query()->where('code', 'BANK-INACTIVE-BRANCH')->exists())->toBeFalse();
});

it('rejects inactive general and mismatched treasury type accounts', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $general = Account::query()->where('store_id', $context['store']->id)->where('code', '1000')->firstOrFail();

    expect(fn () => $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'gl_account_id' => $general->id,
    ])))->toThrow(DomainException::class, 'حساب دفتر کل باید معین')
        ->and(TreasuryAccount::query()->where('code', 'BANK-MAIN')->exists())->toBeFalse();

    expect(fn () => $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context, [
        'type' => TreasuryAccountType::Cash->value,
    ])))->toThrow(DomainException::class, 'مجاز برای این نوع')
        ->and(TreasuryAccount::query()->where('code', 'BANK-MAIN')->exists())->toBeFalse();

    $context['bank']->update(['is_active' => false]);

    expect(fn () => $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context)))
        ->toThrow(DomainException::class, 'در صورت فعال‌بودن حساب خزانه، فعال باشد')
        ->and(TreasuryAccount::query()->where('code', 'BANK-MAIN')->exists())->toBeFalse();
});

it('blocks deactivation and identity changes while a treasury payment is pending', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $treasury = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context));
    $payment = treasuryAccountServicePayment($context, $treasury, 'TREASURY-SERVICE-PENDING');

    expect(fn () => $service->setActive($treasury, false, $treasury->fresh()->version))
        ->toThrow(DomainException::class, 'دریافت یا پرداخت در انتظار تأیید')
        ->and($treasury->refresh()->is_active)->toBeTrue()
        ->and($treasury->refresh()->is_default)->toBeTrue()
        ->and($treasury->refresh()->version)->toBe(0)
        ->and($payment->refresh()->status)->toBe(TreasuryDocumentStatus::Pending);

    expect(fn () => $service->update($treasury, treasuryAccountServicePayload($context, [
        'name' => 'نام غیرمجاز',
        'is_active' => false,
        'version' => 0,
    ])))->toThrow(DomainException::class, 'دریافت یا پرداخت در انتظار تأیید')
        ->and($treasury->refresh()->name)->toBe('بانک اصلی')
        ->and($treasury->refresh()->is_active)->toBeTrue()
        ->and($treasury->refresh()->version)->toBe(0);

    expect(fn () => $service->update($treasury, treasuryAccountServicePayload($context, [
        'code' => 'BANK-CHANGED',
        'type' => TreasuryAccountType::Cash->value,
        'gl_account_id' => $context['cash']->id,
        'version' => 0,
    ])))->toThrow(DomainException::class, 'کد، نوع و حساب دفتر کل حساب دارای گردش مالی')
        ->and($treasury->refresh()->code)->toBe('BANK-MAIN')
        ->and($treasury->refresh()->type)->toBe(TreasuryAccountType::Bank)
        ->and($treasury->refresh()->gl_account_id)->toBe($context['bank']->id)
        ->and($treasury->refresh()->version)->toBe(0);
});

it('freezes confirmed payment identity but permits safe deactivation and archival maintenance', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $treasury = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context));
    $payment = treasuryAccountServicePayment(
        $context,
        $treasury,
        'TREASURY-SERVICE-CONFIRMED',
        TreasuryDocumentStatus::Confirmed,
    );

    expect(fn () => $service->update($treasury, treasuryAccountServicePayload($context, [
        'code' => 'BANK-CONFIRMED-CHANGED',
        'version' => 0,
    ])))->toThrow(DomainException::class, 'دارای گردش مالی')
        ->and($treasury->refresh()->code)->toBe('BANK-MAIN')
        ->and($payment->refresh()->status)->toBe(TreasuryDocumentStatus::Confirmed);

    $renamed = $service->update($treasury, treasuryAccountServicePayload($context, [
        'name' => 'بانک دارای گردش',
        'version' => 0,
    ]));
    $deactivated = $service->setActive($renamed, false, $renamed->version);
    $context['bank']->update(['is_active' => false]);
    $archived = $service->update($deactivated, treasuryAccountServicePayload($context, [
        'name' => 'بانک آرشیوی',
        'is_active' => false,
        'is_default' => false,
        'version' => $deactivated->version,
    ]));

    expect($renamed->name)->toBe('بانک دارای گردش')
        ->and($renamed->version)->toBe(1)
        ->and($deactivated->is_active)->toBeFalse()
        ->and($deactivated->is_default)->toBeFalse()
        ->and($deactivated->default_slot)->toBeNull()
        ->and($deactivated->version)->toBe(2)
        ->and($archived->name)->toBe('بانک آرشیوی')
        ->and($archived->is_active)->toBeFalse()
        ->and($archived->gl_account_id)->toBe($context['bank']->id)
        ->and($archived->version)->toBe(3);
});

it('locks bank identity after check settlement without treating the check as pending work', function () {
    $context = treasuryAccountServiceContext();
    $service = app(TreasuryAccountService::class);
    $treasury = $service->create($context['store'], $context['branch'], treasuryAccountServicePayload($context));
    DB::table('checks')->insert([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'check_number' => 'TREASURY-SERVICE-CHECK',
        'bank_name' => 'بانک تسویه',
        'amount' => '100.00',
        'due_date' => '2025-06-15',
        'type' => 'receivable',
        'status' => 'collected',
        'settlement_treasury_account_id' => $treasury->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $service->update($treasury, treasuryAccountServicePayload($context, [
        'code' => 'BANK-CHECK-CHANGED',
        'version' => 0,
    ])))->toThrow(DomainException::class, 'دارای گردش مالی')
        ->and($treasury->refresh()->code)->toBe('BANK-MAIN')
        ->and($treasury->refresh()->version)->toBe(0);

    $deactivated = $service->setActive($treasury, false, 0);

    expect($deactivated->is_active)->toBeFalse()
        ->and($deactivated->is_default)->toBeFalse()
        ->and($deactivated->version)->toBe(1)
        ->and(DB::table('checks')->where('settlement_treasury_account_id', $treasury->id)->value('status'))
        ->toBe('collected');
});
