<?php

namespace App\Enums;

enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';
}
