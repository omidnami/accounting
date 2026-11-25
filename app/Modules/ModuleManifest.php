<?php

namespace App\Modules;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    /**
     * @param  array<int, string>  $profiles
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $version,
        public array $profiles,
        public ?string $provider,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        if (! is_string($config['label'] ?? null)
            || ! is_string($config['version'] ?? null)
            || ! is_array($config['profiles'] ?? null)
            || ! is_string($config['provider'] ?? null) && ($config['provider'] ?? null) !== null) {
            throw new InvalidArgumentException("Module manifest [{$name}] is invalid.");
        }

        return new self(
            $name,
            $config['label'],
            $config['version'],
            array_values(array_map('strval', $config['profiles'])),
            $config['provider'] ?? null,
        );
    }
}
