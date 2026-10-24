<?php

namespace App\Enums;

enum ProductStockPolicy: string
{
    case Simple = 'simple';
    case MinMax = 'min_max';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'نقطه سفارش ساده',
            self::MinMax => 'حداقل و حداکثر موجودی',
        };
    }
}
