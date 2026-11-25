<?php

use App\Models\Store;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

it('resolves today in the store timezone while the app stays in UTC', function () {
    config(['app.timezone' => 'UTC', 'app.business_timezone' => 'Asia/Tehran']);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 21:00:00', 'UTC'));
    $store = new Store(['timezone' => 'Asia/Tehran']);

    $clock = app(BusinessClock::class);

    expect(today()->toDateString())->toBe('2026-11-24')
        ->and($clock->today($store)->toDateString())->toBe('2026-11-25')
        ->and($clock->today()->toDateString())->toBe('2026-11-25');
});

it('falls back to the configured business timezone for stores without one', function () {
    config(['app.business_timezone' => 'Asia/Tehran']);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 21:00:00', 'UTC'));

    expect(app(BusinessClock::class)->today(new Store)->toDateString())
        ->toBe('2026-11-25');
});
