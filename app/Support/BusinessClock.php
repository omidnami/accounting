<?php

namespace App\Support;

use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * Resolves "today" and "now" in the store's business timezone.
 *
 * The application runs on UTC, but financial documents, reports and the POS
 * must use the store's wall clock. Otherwise sales between 00:00 and 03:30
 * Tehran time are recorded on the previous day.
 */
final class BusinessClock
{
    /** @var array<int, string> */
    private array $timezones = [];

    public function timezone(Store|int|null $store = null): string
    {
        $fallback = (string) config(
            'app.business_timezone',
            config('app.timezone', 'UTC'),
        );

        if ($store instanceof Store) {
            return $store->timezone ?: $fallback;
        }

        if ($store === null) {
            return $fallback;
        }

        return $this->timezones[$store] ??= (string) (
            Store::query()->whereKey($store)->value('timezone') ?: $fallback
        );
    }

    public function now(Store|int|null $store = null): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($store));
    }

    public function today(Store|int|null $store = null): CarbonImmutable
    {
        return $this->now($store)->startOfDay();
    }
}
