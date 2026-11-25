<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {file : Encrypted backup file}
        {--database : Restore the database}
        {--files : Restore application files}
        {--force : Required in production}';

    protected $description = 'Restore an encrypted backup after integrity verification';

    public function handle(BackupService $backups): int
    {
        $file = $this->argument('file');

        if (! is_string($file) || ! is_file($file)) {
            $this->error('Backup file not found.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('--force is required for production restores.');

            return self::FAILURE;
        }

        $database = (bool) $this->option('database') || ! $this->option('files');
        $files = (bool) $this->option('files') || ! $this->option('database');

        try {
            $backups->restore($file, $database, $files);
            $this->info('Backup restored successfully.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
