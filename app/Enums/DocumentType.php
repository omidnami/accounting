<?php

namespace App\Enums;

enum DocumentType: string
{
    case JournalEntry = 'journal_entry';
    case SalesInvoice = 'sales_invoice';
    case SalesReturn = 'sales_return';
    case PurchaseInvoice = 'purchase_invoice';
    case PurchaseReturn = 'purchase_return';
    case Quotation = 'quotation';
    case Receipt = 'receipt';
    case Payment = 'payment';
    case Check = 'check';
}
