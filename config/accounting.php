<?php

return [
    'pdf_renderer_binary' => env('PDF_RENDERER_BINARY', '/usr/bin/google-chrome'),
    /*
    |--------------------------------------------------------------------------
    | Default account mapping
    |--------------------------------------------------------------------------
    |
    | Logical accounting keys are resolved to account codes. Account codes are
    | intentionally kept here instead of being hard-coded in posting workflows.
    |
    */
    'default_accounts' => [
        'cash' => '1101',
        'bank' => '1102',
        'accounts_receivable' => '1103',
        'checks_receivable' => '1107',
        'inventory' => '1104',
        'vat_receivable' => '1106',
        'accounts_payable' => '2101',
        'vat_payable' => '2102',
        'checks_payable' => '2103',
        'sales' => '4101',
        'sales_returns' => '4102',
        'sales_discounts' => '4103',
        'inventory_adjustment_gain' => '4104',
        'cogs' => '5101',
        'purchase_return_difference' => '5106',
        'inventory_adjustment_loss' => '5107',
        'services_expense' => '5108',
        'retained_earnings' => '3102',
    ],

    'account_code_pattern' => '/^\d{4}$/',

    'account_type_prefixes' => [
        'asset' => '1',
        'liability' => '2',
        'equity' => '3',
        'revenue' => '4',
        'expense' => '5',
    ],

    'contra_account_codes' => ['4102', '4103'],

    'treasury_account_gl_codes' => [
        'cash' => '1101',
        'bank' => '1102',
        'card' => '1102',
    ],
];
