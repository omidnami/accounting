<?php

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Check;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use App\Models\TreasuryAccount;
use App\Models\User;

return [
    'models' => [
        Account::class,
        AccountingPeriod::class,
        Check::class,
        FiscalYear::class,
        InventoryMovement::class,
        Invoice::class,
        InvoiceItem::class,
        JournalEntry::class,
        Party::class,
        Payment::class,
        PaymentAllocation::class,
        Product::class,
        Receipt::class,
        ReceiptAllocation::class,
        TreasuryAccount::class,
        User::class,
    ],

    'excluded_attributes' => [
        'created_at',
        'updated_at',
        'remember_token',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],
];
