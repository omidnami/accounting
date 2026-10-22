<?php

namespace App\Enums;

enum TreasuryDocumentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';
}
