<?php

use App\Enums\CalendarType;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use Database\Seeders\StoreSeeder;

it('calculates Jalali fiscal year boundaries including leap years', function () {
    $dates = app(JalaliDateService::class);
    $range = $dates->fiscalYearRange(1405);
    $periods = $dates->periods(1405);

    expect($range['starts_on']->toDateString())->toBe('2026-03-21')
        ->and($range['ends_on']->toDateString())->toBe('2027-03-20')
        ->and($periods)->toHaveCount(12)
        ->and($periods[0]['starts_on']->toDateString())->toBe('2026-03-21')
        ->and($periods[11]['ends_on']->toDateString())->toBe('2027-03-20')
        ->and($dates->isLeapYear(1403))->toBeTrue()
        ->and($dates->daysInMonth(1403, 12))->toBe(30)
        ->and($dates->daysInMonth(1404, 12))->toBe(29);
});

it('creates twelve contiguous periods and default sequences', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $fiscalYear = app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $fiscalYear->load(['periods', 'documentSequences']);

    expect($fiscalYear->calendar)->toBe(CalendarType::Jalali)
        ->and($fiscalYear->periods)->toHaveCount(12)
        ->and($fiscalYear->documentSequences)->toHaveCount(7)
        ->and($fiscalYear->periods->first()->starts_on->toDateString())->toBe('2026-03-21')
        ->and($fiscalYear->periods->last()->ends_on->toDateString())->toBe('2027-03-20');

    $fiscalYear->periods->sortBy('period_number')->sliding(2)->each(function ($pair): void {
        expect($pair->first()->ends_on->addDay()->toDateString())
            ->toBe($pair->last()->starts_on->toDateString());
    });
});

it('rejects overlapping fiscal years', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);

    FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => 'دوره هم‌پوشان',
        'calendar' => CalendarType::Jalali,
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-06-01',
    ]);
})->throws(DomainException::class);

it('prevents overlapping accounting periods', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $fiscalYear = app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);

    $fiscalYear->periods()->create([
        'store_id' => $store->id,
        'name' => 'دوره تکراری',
        'period_number' => 13,
        'starts_on' => '2026-03-25',
        'ends_on' => '2026-03-30',
    ]);
})->throws(DomainException::class);
