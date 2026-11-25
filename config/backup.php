<?php

return [
    'path' => storage_path('app/backups'),
    'passphrase' => env('BACKUP_PASSPHRASE'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'files' => [
        storage_path('app/public'),
        storage_path('app/private'),
    ],
    'mysql_dump_binary' => env('MYSQL_DUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'alert_email' => env('BACKUP_ALERT_EMAIL'),
];
