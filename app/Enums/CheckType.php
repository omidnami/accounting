<?php

namespace App\Enums;

enum CheckType: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
