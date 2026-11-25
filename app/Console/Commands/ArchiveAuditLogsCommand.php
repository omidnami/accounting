<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ArchiveAuditLogsCommand extends Command
{
    protected $signature = 'audit:archive {--days= : Retention days} {--force : Required in production}';

    protected $description = 'Archive old audit logs to JSONL and prune them from the active table';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('--force is required in production.');

            return self::FAILURE;
        }

        $days = max(1, (int) ($this->option('days') ?? config('audit.retention_days', 365)));
        $cutoff = now()->subDays($days);
        $path = rtrim((string) config('audit.archive_path'), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($path);
        $archive = $path.'/audit-'.$cutoff->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.jsonl.gz';
        $stream = gzopen($archive, 'wb');

        if ($stream === false) {
            $this->error('Cannot create audit archive.');

            return self::FAILURE;
        }

        $pruned = 0;

        try {
            DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($stream, &$pruned): void {
                    foreach ($rows as $row) {
                        gzwrite($stream, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                        $pruned++;
                    }

                    DB::table('audit_logs')->whereIn('id', $rows->pluck('id'))->delete();
                });
        } finally {
            gzclose($stream);
        }

        $this->info("Archived and pruned {$pruned} audit row(s): {$archive}");

        return self::SUCCESS;
    }
}
