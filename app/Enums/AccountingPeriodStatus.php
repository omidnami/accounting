<?php

namespace App\Enums;

enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case SoftClosed = 'soft_closed';
    case Closed = 'closed';
}
