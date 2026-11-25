<?php

return [
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),
    'retry_seconds' => (int) env('OUTBOX_RETRY_SECONDS', 60),
    'processing_timeout' => (int) env('OUTBOX_PROCESSING_TIMEOUT', 300),
];
