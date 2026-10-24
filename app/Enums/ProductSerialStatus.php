<?php

namespace App\Enums;

enum ProductSerialStatus: string
{
    case Available = 'available';
    case Sold = 'sold';
    case Returned = 'returned';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'موجود',
            self::Sold => 'فروخته‌شده',
            self::Returned => 'برگشت‌خورده',
            self::Void => 'باطل',
        };
    }
}
