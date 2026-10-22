<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Sales = 'sales';
    case Purchase = 'purchase';
    case Quotation = 'quotation';
}
