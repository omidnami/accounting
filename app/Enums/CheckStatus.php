<?php

namespace App\Enums;

enum CheckStatus: string
{
    case Pending = 'pending';
    case Collected = 'collected';
    case Bounced = 'bounced';
    case Spent = 'spent';
}
