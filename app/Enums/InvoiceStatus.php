<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
