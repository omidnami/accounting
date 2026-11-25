<?php

namespace App\Modules;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ModuleRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $manifests;

    public function __construct()
    {
        $this->manifests = [];

        foreach ((array) config('modules.catalog', []) as $name => $config) {
            if (! is_string($name) || ! is_array($config)) {
                throw new InvalidArgumentException('Module catalog entries must be named arrays.');
            }

            $manifest = ModuleManifest::fromConfig($name, $config);

            if ($manifest->provider !== null
                && (! class_exists($manifest->provider)
                    || ! is_subclass_of($manifest->provider, ServiceProvider::class))) {
                throw new InvalidArgumentException("Module provider [{$manifest->provider}] is not an approved service provider.");
            }

            $this->manifests[$name] = $manifest;
        }
    }

    public function get(string $name): ModuleManifest
    {
        return $this->manifests[$name] ?? throw new InvalidArgumentException("Unknown module [{$name}].");
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->manifests;
    }
}
