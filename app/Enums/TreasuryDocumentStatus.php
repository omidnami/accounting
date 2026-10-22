<?php

namespace App\Enums;

enum TreasuryDocumentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
