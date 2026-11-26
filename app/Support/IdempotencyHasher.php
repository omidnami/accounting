<?php

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;
use UnitEnum;

final class IdempotencyHasher
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hash(string $operation, array $payload): string
    {
        $canonical = [
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => $operation,
            'payload' => self::normalize($payload),
        ];

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return self::normalize($value->value);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Idempotency payload cannot contain non-finite numbers.');
            }

            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            ksort($value);

            return array_map(self::normalize(...), $value);
        }

        throw new InvalidArgumentException('Idempotency payload contains an unsupported value.');
    }
}
