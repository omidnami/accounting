<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';
}
