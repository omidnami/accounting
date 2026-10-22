<?php

namespace App\Support;

use InvalidArgumentException;

final class Decimal
{
    public const QUANTITY_SCALE = 3;

    public const UNIT_COST_SCALE = 4;

    public const MONEY_SCALE = 2;

    public static function normalize(string|int|float $value, int $scale): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('مقدار عددی معتبر نیست.');
        }

        return self::round((string) $value, $scale);
    }

    public static function add(string|int|float $left, string|int|float $right, int $scale): string
    {
        return self::round(bcadd((string) $left, (string) $right, $scale + 4), $scale);
    }

    public static function subtract(string|int|float $left, string|int|float $right, int $scale): string
    {
        return self::round(bcsub((string) $left, (string) $right, $scale + 4), $scale);
    }

    public static function multiply(string|int|float $left, string|int|float $right, int $scale): string
    {
        return self::round(bcmul((string) $left, (string) $right, $scale + 4), $scale);
    }

    public static function divide(string|int|float $left, string|int|float $right, int $scale): string
    {
        if (bccomp((string) $right, '0', $scale + 4) === 0) {
            throw new InvalidArgumentException('تقسیم بر صفر در محاسبات اعشاری مجاز نیست.');
        }

        return self::round(bcdiv((string) $left, (string) $right, $scale + 4), $scale);
    }

    public static function compare(
        string|int|float $left,
        string|int|float $right,
        int $scale = self::QUANTITY_SCALE,
    ): int {
        return bccomp((string) $left, (string) $right, $scale);
    }

    public static function isPositive(string|int|float $value): bool
    {
        return bccomp((string) $value, '0', self::UNIT_COST_SCALE) === 1;
    }

    public static function isZero(string|int|float $value): bool
    {
        return bccomp((string) $value, '0', self::UNIT_COST_SCALE) === 0;
    }

    private static function round(string $value, int $scale): string
    {
        $factor = bcpow('10', (string) $scale, 0);
        $scaled = bcmul($value, $factor, $scale + 4);
        $rounded = bccomp($scaled, '0', $scale + 4) < 0
            ? bcsub($scaled, '0.5', 0)
            : bcadd($scaled, '0.5', 0);

        return bcdiv($rounded, $factor, $scale);
    }
}
