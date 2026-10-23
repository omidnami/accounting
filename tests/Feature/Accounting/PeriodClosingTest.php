<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalEntryKind;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Accounting\PeriodClosingService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{store: Store, year: FiscalYear, next_year: FiscalYear}
 */
function periodClosingContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $year = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->firstOrFail();
    $nextYear = app(FiscalYearSetupService::class)->setupJalaliYear(
        $store,
        app(JalaliDateService::class)->currentYear() + 1,
    );

    return compact('store', 'year', 'nextYear') + ['next_year' => $nextYear];
}

function periodClosingUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('soft closes, hard closes and reopens a period with audit reasons', function () {
    ['store' => $store, 'year' => $year] = periodClosingContext();
    $manager = periodClosingUser(UserRole::Manager);
    $service = app(PeriodClosingService::class);
    $period = $year->periods()->orderBy('period_number')->firstOrFail();

    $softClosed = $service->closePeriod(
        $store,
        $period,
        AccountingPeriodStatus::SoftClosed,
        $manager,
        'پایان بررسی موقت دوره',
    );
    expect($softClosed->status)->toBe(AccountingPeriodStatus::SoftClosed)
        ->and($softClosed->closed_by)->toBe($manager->id)
        ->and($softClosed->closed_at)->not->toBeNull();

    $closed = $service->closePeriod(
        $store,
        $period->fresh(),
        AccountingPeriodStatus::Closed,
        $manager,
        'تأیید نهایی دوره',
    );
    expect($closed->status)->toBe(AccountingPeriodStatus::Closed);

    $reopened = $service->reopenPeriod(
        $store,
        $closed,
        $manager,
        'اصلاح سند کشف‌شده در بازبینی',
    );
    expect($reopened->status)->toBe(AccountingPeriodStatus::Open)
        ->and($reopened->closed_by)->toBeNull()
        ->and($reopened->closed_at)->toBeNull()
        ->and(AuditLog::query()->where('event', 'period_closed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'period_reopened')->exists())->toBeTrue();
});

it('blocks period closing while draft accounting documents remain', function () {
    ['store' => $store, 'year' => $year] = periodClosingContext();
    $manager = periodClosingUser(UserRole::Manager);
    $period = $year->periods()->orderBy('period_number')->firstOrFail();
    $cash = app(SettingService::class)->defaultAccount('cash', $store->id);
    $sales = app(SettingService::class)->defaultAccount('sales', $store->id);
    app(PostingService::class)->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $store->defaultBranch->id,
            'fiscal_year_id' => $year->id,
            'accounting_period_id' => $period->id,
            'entry_kind' => JournalEntryKind::Manual,
            'entry_date' => $period->starts_on->toDateString(),
            'description' => 'پیش‌نویس باز برای کنترل بستن',
            'created_by' => $manager->id,
        ],
        [
            ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100],
        ],
    );

    expect(fn () => app(PeriodClosingService::class)->closePeriod(
        $store,
        $period,
        AccountingPeriodStatus::Closed,
        $manager,
    ))->toThrow(DomainException::class)
        ->and($period->fresh()->status)->toBe(AccountingPeriodStatus::Open)
        ->and(JournalEntry::query()->where('status', 'draft')->count())->toBe(1);
});

it('closes a year only after all periods are hard closed and advances current year', function () {
    ['store' => $store, 'year' => $year, 'next_year' => $nextYear] = periodClosingContext();
    $manager = periodClosingUser(UserRole::Manager);
    $service = app(PeriodClosingService::class);

    foreach ($year->periods()->orderBy('period_number')->get() as $period) {
        $service->closePeriod(
            $store,
            $period,
            AccountingPeriodStatus::Closed,
            $manager,
            'بستن دوره برای اختتامیه سال',
        );
    }

    $closed = $service->closeFiscalYear(
        $store,
        $year,
        $manager,
        'پایان سال مالی و انتقال به سال بعد',
    );

    expect($closed->status)->toBe(FiscalYearStatus::Closed)
        ->and($closed->is_current)->toBeFalse()
        ->and($closed->closed_by)->toBe($manager->id)
        ->and($nextYear->fresh()->is_current)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'fiscal_year_closed')->exists())->toBeTrue();

    $reopened = $service->reopenFiscalYear(
        $store,
        $closed,
        $manager,
        'اصلاح گزارش سالانه پس از بستن',
    );

    expect($reopened->status)->toBe(FiscalYearStatus::Open)
        ->and($reopened->closed_at)->toBeNull()
        ->and(AuditLog::query()->where('event', 'fiscal_year_reopened')->exists())->toBeTrue();
});

it('does not close a fiscal year while any period is still open', function () {
    ['store' => $store, 'year' => $year] = periodClosingContext();
    $manager = periodClosingUser(UserRole::Manager);

    expect(fn () => app(PeriodClosingService::class)->closeFiscalYear(
        $store,
        $year,
        $manager,
        'تلاش برای بستن زودهنگام سال',
    ))->toThrow(DomainException::class)
        ->and($year->fresh()->status)->toBe(FiscalYearStatus::Open);
});

it('allows accountants to view and close periods but not close the fiscal year', function () {
    ['store' => $store, 'year' => $year] = periodClosingContext();
    $accountant = periodClosingUser(UserRole::Accountant);
    $period = $year->periods()->orderBy('period_number')->firstOrFail();

    $this->actingAs($accountant)
        ->get(route('fiscal-years.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.manage_years', false)
            ->where('permissions.close_periods', true)
            ->where('permissions.reopen_periods', false)
            ->where('permissions.close_year', false));

    $this->actingAs($accountant)
        ->patch(route('fiscal-years.periods.close', $period), [
            'status' => AccountingPeriodStatus::Closed->value,
            'reason' => 'بستن دوره توسط حسابدار',
        ])
        ->assertRedirect(route('fiscal-years.index'));

    expect($period->fresh()->status)->toBe(AccountingPeriodStatus::Closed);

    $this->actingAs($accountant)
        ->patch(route('fiscal-years.close', $year), [
            'reason' => 'تلاش حسابدار برای بستن سال',
        ])
        ->assertForbidden();

    $this->actingAs($accountant)
        ->post(route('fiscal-years.store'), [
            'year' => app(JalaliDateService::class)->currentYear() + 2,
        ])
        ->assertForbidden();

    expect($store->fiscalYears()->count())->toBe(2);
});
