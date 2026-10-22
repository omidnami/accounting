<?php

namespace App\Enums;

enum DocumentType: string
{
    case JournalEntry = 'journal_entry';
    case SalesInvoice = 'sales_invoice';
    case PurchaseInvoice = 'purchase_invoice';
    case Quotation = 'quotation';
    case Receipt = 'receipt';
    case Payment = 'payment';
    case Check = 'check';
}
