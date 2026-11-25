<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupStatusCommand extends Command
{
    protected $signature = 'backup:status';

    protected $description = 'Fail when the latest encrypted backup is missing or stale';

    public function handle(): int
    {
        $files = glob(rtrim((string) config('backup.path'), DIRECTORY_SEPARATOR).'/*.bak.enc') ?: [];
        $latest = $files === [] ? null : (int) max(array_map('filemtime', $files));

        if ($latest === null || $latest < now()->subHours(26)->getTimestamp()) {
            $this->error('No recent encrypted backup was found.');

            return self::FAILURE;
        }

        $this->info('Recent encrypted backup found: '.date('Y-m-d H:i:s', $latest));

        return self::SUCCESS;
    }
}
