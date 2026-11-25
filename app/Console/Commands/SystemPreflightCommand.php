<?php

namespace App\Console\Commands;

use App\Models\User;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemPreflightCommand extends Command
{
    protected $signature = 'system:preflight';

    protected $description = 'Validate production configuration before deployment';

    public function handle(): int
    {
        $issues = [];
        $production = app()->environment('production');

        if (! is_string(config('app.key')) || config('app.key') === '') {
            $issues[] = 'APP_KEY is missing.';
        }

        if (config('app.debug')) {
            $issues[] = 'APP_DEBUG must be false.';
        }

        $timezone = (string) config('app.business_timezone');

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $issues[] = 'BUSINESS_TIMEZONE is invalid.';
        }

        if (! is_writable(storage_path())) {
            $issues[] = 'storage/ is not writable.';
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $exception) {
            $issues[] = 'Database connection failed: '.$exception->getMessage();
        }

        if ($production) {
            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $issues[] = 'APP_URL must use HTTPS in production.';
            }

            if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $issues[] = 'Production requires MySQL or MariaDB.';
            }

            if ((string) config('backup.passphrase', '') === '') {
                $issues[] = 'BACKUP_PASSPHRASE is missing.';
            }

            if (config('queue.default') === 'sync') {
                $issues[] = 'Production queue must not use the sync driver.';
            }

            if (Schema::hasTable('users') && ! User::query()->exists()) {
                $issues[] = 'First-run setup has not been completed.';
            }
        }

        if ($issues !== []) {
            foreach ($issues as $issue) {
                $this->error($issue);
            }

            return self::FAILURE;
        }

        $this->info('System preflight passed.');

        return self::SUCCESS;
    }
}
