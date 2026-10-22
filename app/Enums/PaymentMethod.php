<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Card = 'card';
    case Check = 'check';
}
