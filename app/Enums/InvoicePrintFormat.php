<?php

namespace App\Enums;

enum InvoicePrintFormat: string
{
    case Thermal58 = 'thermal_58';
    case Thermal80 = 'thermal_80';
    case A5 = 'a5';
    case A4 = 'a4';

    public function isThermal(): bool
    {
        return in_array($this, [self::Thermal58, self::Thermal80], true);
    }

    public function thermalPaperWidth(): int
    {
        return $this === self::Thermal58 ? 58 : 80;
    }
}
