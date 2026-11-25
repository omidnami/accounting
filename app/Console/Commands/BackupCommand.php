<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Create an encrypted database and file backup';

    public function handle(BackupService $backups): int
    {
        try {
            $path = $backups->create();
            $this->info("Encrypted backup created: {$path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
