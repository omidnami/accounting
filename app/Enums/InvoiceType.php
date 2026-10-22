<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Sales = 'sales';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case SalesReturn = 'sales_return';
    case Quotation = 'quotation';
}
