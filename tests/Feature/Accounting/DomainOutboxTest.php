<?php

use App\Events\DomainEventDispatched;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\OutboxEvent;
use App\Models\Store;
use App\Services\Accounting\Contracts\PeriodCloseBlocker;
use App\Services\Accounting\PeriodCloseBlockerRegistry;
use App\Services\Events\DomainEventService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
});

it('records domain events atomically with the aggregate transaction', function () {
    $store = Store::query()->firstOrFail();

    expect(fn () => DB::transaction(function () use ($store): void {
        app(DomainEventService::class)->record('test.atomic', $store, ['value' => 1]);

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('processes durable events and retries transient listener failures', function () {
    config(['outbox.max_attempts' => 3, 'outbox.retry_seconds' => 0]);
    $store = Store::query()->firstOrFail();
    $events = app(DomainEventService::class);
    $events->record('test.success', $store);
    $events->record('test.retry', $store);
    $attempts = 0;

    Event::listen(DomainEventDispatched::class, function (DomainEventDispatched $event) use (&$attempts): void {
        if ($event->event->event_name === 'test.retry' && $attempts++ === 0) {
            throw new RuntimeException('transient failure');
        }
    });

    $this->artisan('outbox:process')->assertFailed();
    expect(OutboxEvent::query()->where('event_name', 'test.success')->firstOrFail()->status)->toBe('processed')
        ->and(OutboxEvent::query()->where('event_name', 'test.retry')->firstOrFail()->status)->toBe('pending');

    OutboxEvent::query()->where('event_name', 'test.retry')->update(['available_at' => now()]);
    $this->artisan('outbox:process')->assertSuccessful();
    expect(OutboxEvent::query()->where('event_name', 'test.retry')->firstOrFail()->status)->toBe('processed');
});

it('allows modules to contribute period-close blockers', function () {
    $store = Store::query()->firstOrFail();
    $year = $store->fiscalYears()->firstOrFail();
    $registry = app(PeriodCloseBlockerRegistry::class);
    $registry->register(new class implements PeriodCloseBlocker
    {
        public function count(Store $store, FiscalYear $fiscalYear, ?AccountingPeriod $period = null): int
        {
            return 2;
        }
    });

    expect($registry->count($store, $year))->toBeGreaterThanOrEqual(2);
});
