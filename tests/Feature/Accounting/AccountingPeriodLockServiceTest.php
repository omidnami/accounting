<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\AccountingPeriodLockService;

/**
 * @return array{store: Store, branch: Branch, fiscal_year: FiscalYear, period: AccountingPeriod}
 */
function accountingPeriodLockServiceContext(): array
{
    $store = Store::query()->create([
        'code' => 'PERIOD-LOCK-STORE',
        'name' => 'فروشگاه قفل دوره',
        'is_active' => true,
    ]);
    $branch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'MAIN',
        'name' => 'شعبه اصلی',
        'is_default' => true,
        'is_active' => true,
    ]);
    $fiscalYear = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => '2025',
        'starts_on' => '2025-01-01',
        'ends_on' => '2025-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);
    $period = AccountingPeriod::query()->create([
        'store_id' => $store->id,
        'fiscal_year_id' => $fiscalYear->id,
        'name' => 'ژوئن',
        'period_number' => 6,
        'starts_on' => '2025-06-01',
        'ends_on' => '2025-06-30',
        'status' => AccountingPeriodStatus::Open,
    ]);

    return compact('store', 'branch', 'fiscalYear', 'period');
}

it('resolves the current open year and exact period without changing lifecycle data', function () {
    $context = accountingPeriodLockServiceContext();

    $result = app(AccountingPeriodLockService::class)->lockCurrentOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        '2025-06-15 14:30:00',
    );

    expect($result['branch']->is($context['branch']))->toBeTrue()
        ->and($result['fiscal_year']->is($context['fiscalYear']))->toBeTrue()
        ->and($result['period']->is($context['period']))->toBeTrue()
        ->and($result['fiscal_year']->store_id)->toBe($context['store']->id)
        ->and($result['period']->fiscal_year_id)->toBe($context['fiscalYear']->id)
        ->and($context['period']->fresh()->status)->toBe(AccountingPeriodStatus::Open)
        ->and($context['period']->fresh()->closed_by)->toBeNull()
        ->and($context['period']->fresh()->closed_at)->toBeNull();
});

it('rejects an active branch belonging to another store', function () {
    $context = accountingPeriodLockServiceContext();
    $otherStore = Store::query()->create([
        'code' => 'PERIOD-LOCK-OTHER-STORE',
        'name' => 'فروشگاه دیگر',
        'is_active' => true,
    ]);
    $otherBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'MAIN',
        'name' => 'شعبه فروشگاه دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);

    expect(fn () => app(AccountingPeriodLockService::class)->lockOpenPeriod(
        $context['store']->id,
        $otherBranch->id,
        $context['fiscalYear']->id,
        '2025-06-15',
    ))->toThrow(DomainException::class, 'شعبه انتخاب‌شده فعال و متعلق به فروشگاه نیست.')
        ->and($context['period']->fresh()->status)->toBe(AccountingPeriodStatus::Open)
        ->and($otherBranch->fresh()->store_id)->toBe($otherStore->id);
});

it('rejects an inactive branch or store before resolving a period', function () {
    $context = accountingPeriodLockServiceContext();
    $service = app(AccountingPeriodLockService::class);

    $context['branch']->update(['is_active' => false]);

    expect(fn () => $service->lockOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        $context['fiscalYear']->id,
        '2025-06-15',
    ))->toThrow(DomainException::class);

    $context['branch']->update(['is_active' => true]);
    $context['store']->update(['is_active' => false]);

    expect(fn () => $service->lockOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        $context['fiscalYear']->id,
        '2025-06-15',
    ))->toThrow(DomainException::class)
        ->and($context['period']->fresh()->status)->toBe(AccountingPeriodStatus::Open);
});

it('rejects document dates outside the requested fiscal year', function (string $date) {
    $context = accountingPeriodLockServiceContext();

    expect(fn () => app(AccountingPeriodLockService::class)->lockOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        $context['fiscalYear']->id,
        $date,
    ))->toThrow(DomainException::class, 'تاریخ سند باید داخل یک سال مالی باز باشد.')
        ->and($context['fiscalYear']->fresh()->status)->toBe(FiscalYearStatus::Open);
})->with([
    'before year start' => ['2024-12-31'],
    'after year end' => ['2026-01-01'],
]);

it('does not resolve a closed or non-containing period', function () {
    $context = accountingPeriodLockServiceContext();
    $context['period']->transitionStatus(AccountingPeriodStatus::Closed);

    expect(fn () => app(AccountingPeriodLockService::class)->lockOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        $context['fiscalYear']->id,
        '2025-06-15',
    ))->toThrow(DomainException::class, 'دوره مالی تاریخ سند باز نیست')
        ->and($context['period']->fresh()->status)->toBe(AccountingPeriodStatus::Closed);

    expect(fn () => app(AccountingPeriodLockService::class)->lockOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        $context['fiscalYear']->id,
        '2025-07-01',
    ))->toThrow(DomainException::class, 'دوره مالی تاریخ سند باز نیست');
});

it('does not borrow another store current year when the requested store has none', function () {
    $context = accountingPeriodLockServiceContext();
    $context['fiscalYear']->forceFill(['is_current' => false])->save();
    $otherStore = Store::query()->create([
        'code' => 'PERIOD-LOCK-CURRENT-OTHER',
        'name' => 'فروشگاه دارای سال جاری',
        'is_active' => true,
    ]);
    $otherBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'MAIN',
        'name' => 'شعبه سال جاری دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);
    $otherYear = FiscalYear::query()->create([
        'store_id' => $otherStore->id,
        'title' => '2025',
        'starts_on' => '2025-01-01',
        'ends_on' => '2025-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);
    AccountingPeriod::query()->create([
        'store_id' => $otherStore->id,
        'fiscal_year_id' => $otherYear->id,
        'name' => 'ژوئن',
        'period_number' => 6,
        'starts_on' => '2025-06-01',
        'ends_on' => '2025-06-30',
        'status' => AccountingPeriodStatus::Open,
    ]);

    expect(fn () => app(AccountingPeriodLockService::class)->lockCurrentOpenPeriod(
        $context['store']->id,
        $context['branch']->id,
        '2025-06-15',
    ))->toThrow(DomainException::class, 'سال مالی جاری باز پیدا نشد.')
        ->and($otherYear->fresh()->is_current)->toBeTrue()
        ->and($context['fiscalYear']->fresh()->status)->toBe(FiscalYearStatus::Open);
});
