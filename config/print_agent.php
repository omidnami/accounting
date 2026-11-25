<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Print Agent Lease
    |--------------------------------------------------------------------------
    |
    | A claimed job whose agent disappears is made available to another poll
    | after this many seconds. Long-running print agents may extend the lease
    | with the heartbeat endpoint.
    |
    */

    'lease_seconds' => (int) env('PRINT_AGENT_LEASE_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Print Agent Attempts
    |--------------------------------------------------------------------------
    |
    | Failed leases are retried up to this many times before the job is marked
    | failed permanently.
    |
    */

    'max_attempts' => (int) env('PRINT_AGENT_MAX_ATTEMPTS', 3),
];
