<?php

namespace App\Enums;

enum TreasuryAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Card = 'card';
}
