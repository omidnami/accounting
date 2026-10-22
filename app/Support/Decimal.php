<?php

namespace App\Support;

use InvalidArgumentException;

final class Decimal
{
    public const QUANTITY_SCALE = 3;

    public const UNIT_COST_SCALE = 4;

    public const MONEY_SCALE = 2;

    /**
     * @return numeric-string
     */
    public static function normalize(string|int|float $value, int $scale): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('مقدار عددی معتبر نیست.');
        }

        return self::round((string) $value, $scale);
    }

    /**
     * @return numeric-string
     */
    public static function add(string|int|float $left, string|int|float $right, int $scale): string
    {
        return self::round(bcadd(self::numeric($left), self::numeric($right), $scale + 4), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function subtract(string|int|float $left, string|int|float $right, int $scale): string
    {
        return self::round(bcsub(self::numeric($left), self::numeric($right), $scale + 4), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function multiply(string|int|float $left, string|int|float $right, int $scale): string
    {
        return self::round(bcmul(self::numeric($left), self::numeric($right), $scale + 4), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function divide(string|int|float $left, string|int|float $right, int $scale): string
    {
        $right = self::numeric($right);

        if (bccomp($right, '0', $scale + 4) === 0) {
            throw new InvalidArgumentException('تقسیم بر صفر در محاسبات اعشاری مجاز نیست.');
        }

        return self::round(bcdiv(self::numeric($left), $right, $scale + 4), $scale);
    }

    public static function compare(
        string|int|float $left,
        string|int|float $right,
        int $scale = self::QUANTITY_SCALE,
    ): int {
        return bccomp(self::numeric($left), self::numeric($right), $scale);
    }

    public static function isPositive(string|int|float $value): bool
    {
        return bccomp(self::numeric($value), '0', self::UNIT_COST_SCALE) === 1;
    }

    public static function isZero(string|int|float $value): bool
    {
        return bccomp(self::numeric($value), '0', self::UNIT_COST_SCALE) === 0;
    }

    /**
     * @return numeric-string
     */
    private static function round(string $value, int $scale): string
    {
        $value = self::numeric($value);
        $factor = bcpow('10', (string) $scale, 0);
        $scaled = bcmul($value, $factor, $scale + 4);
        $rounded = bccomp($scaled, '0', $scale + 4) < 0
            ? bcsub($scaled, '0.5', 0)
            : bcadd($scaled, '0.5', 0);

        return bcdiv($rounded, $factor, $scale);
    }

    /**
     * @return numeric-string
     */
    private static function numeric(string|int|float $value): string
    {
        $normalized = (string) $value;

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException('مقدار عددی معتبر نیست.');
        }

        return $normalized;
    }
}
