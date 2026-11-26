<?php

return [
    'enabled' => env('TENANCY_ENABLED', false),
    'connection' => env('TENANCY_DB_CONNECTION'),
    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'localhost,127.0.0.1')),
    ))),
];
