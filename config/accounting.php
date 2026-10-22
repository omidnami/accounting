<?php

return [
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
        'inventory' => '1104',
        'vat_receivable' => '1106',
        'accounts_payable' => '2101',
        'vat_payable' => '2102',
        'sales' => '4101',
        'sales_returns' => '4102',
        'sales_discounts' => '4103',
        'cogs' => '5101',
    ],
];
