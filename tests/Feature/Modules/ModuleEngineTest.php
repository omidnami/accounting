<?php

use App\Models\Store;
use App\Modules\ModuleLifecycleService;
use App\Modules\ModuleManager;

it('uses the approved module catalog and per-store lifecycle overrides', function () {
    $store = Store::query()->firstOrFail();
    $manager = app(ModuleManager::class);

    expect($manager->enabled('pos', $store))->toBeTrue()
        ->and($manager->profiles('pos'))->toContain('retail')
        ->and(array_key_exists('pos', $manager->catalog()))->toBeTrue();

    app(ModuleLifecycleService::class)->disable($store, 'pos');
    expect($manager->enabled('pos', $store))->toBeFalse();

    app(ModuleLifecycleService::class)->enable($store, 'pos');
    expect($manager->enabled('pos', $store))->toBeTrue();
});

it('rejects modules outside the approved catalog', function () {
    $manager = app(ModuleManager::class);
    $store = Store::query()->firstOrFail();

    $manager->enabled('tenant-uploaded-zip', $store);
})->throws(InvalidArgumentException::class, 'Unknown module');
