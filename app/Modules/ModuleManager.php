<?php

namespace App\Modules;

use App\Models\Store;
use App\Models\StoreModule;
use Illuminate\Support\Facades\Schema;

class ModuleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {}

    public function enabled(string $name, ?Store $store = null): bool
    {
        $this->registry->get($name);

        if ($store !== null && Schema::hasTable('store_modules')) {
            $override = StoreModule::query()
                ->where('store_id', $store->id)
                ->where('module', $name)
                ->value('enabled');

            if ($override !== null) {
                return (bool) $override;
            }
        }

        return in_array($name, (array) config('modules.enabled', []), true);
    }

    /**
     * @return array<int, string>
     */
    public function profiles(string $name): array
    {
        return $this->registry->get($name)->profiles;
    }

    /** @return array<string, ModuleManifest> */
    public function catalog(): array
    {
        return $this->registry->all();
    }
}
