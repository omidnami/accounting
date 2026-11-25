<?php

namespace App\Modules;

use App\Models\Store;
use App\Models\StoreModule;
use App\Services\Audit\AuditLogService;

class ModuleLifecycleService
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AuditLogService $audit,
    ) {}

    public function enable(Store $store, string $module): void
    {
        $this->registry->get($module);
        StoreModule::query()->updateOrCreate(
            ['store_id' => $store->id, 'module' => $module],
            ['enabled' => true],
        );
        $this->audit->record('module_enabled', $store, [], ['module' => $module]);
    }

    public function disable(Store $store, string $module): void
    {
        $this->registry->get($module);
        StoreModule::query()->updateOrCreate(
            ['store_id' => $store->id, 'module' => $module],
            ['enabled' => false],
        );
        $this->audit->record('module_disabled', $store, [], ['module' => $module]);
    }
}
