<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RuntimeException;

class BackupService
{
    public function __construct(
        private readonly EncryptedArchiveService $archive,
    ) {}

    public function create(): string
    {
        $passphrase = (string) config('backup.passphrase');

        if ($passphrase === '') {
            throw new RuntimeException('BACKUP_PASSPHRASE is required.');
        }

        $backupPath = rtrim((string) config('backup.path'), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($backupPath);
        $temp = $backupPath.'/.tmp-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temp.'/work');
        $databaseFile = $this->dumpDatabase($temp.'/work');
        $files = $this->copyFiles($temp.'/work/files');
        $manifest = [
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'database_driver' => DB::connection()->getDriverName(),
            'database_file' => $databaseFile,
            'files' => $files,
        ];
        file_put_contents(
            $temp.'/work/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        $tar = $temp.'/archive.tar';
        $phar = new PharData($tar);
        $phar->buildFromDirectory($temp.'/work');
        $phar->compress(Phar::GZ);
        unset($phar);
        $destination = $backupPath.'/backup-'.now()->format('Ymd-His').'-'.Str::random(8).'.bak.enc';

        try {
            $this->archive->encrypt($tar.'.gz', $destination, $passphrase);
            $this->applyRetention($backupPath);

            return $destination;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    public function restore(string $source, bool $database, bool $files): void
    {
        $passphrase = (string) config('backup.passphrase');

        if ($passphrase === '') {
            throw new RuntimeException('BACKUP_PASSPHRASE is required.');
        }

        $temp = rtrim((string) config('backup.path'), DIRECTORY_SEPARATOR).'/.restore-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temp.'/extracted');

        try {
            $decrypted = $temp.'/archive.tar.gz';
            $this->archive->decrypt($source, $decrypted, $passphrase);
            $phar = new PharData($decrypted);
            $phar->extractTo($temp.'/extracted');
            unset($phar);
            $manifestPath = $temp.'/extracted/manifest.json';

            if (! is_file($manifestPath)) {
                throw new RuntimeException('Backup manifest not found.');
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            if (($manifest['version'] ?? null) !== 1
                || ($manifest['database_driver'] ?? null) !== DB::connection()->getDriverName()) {
                throw new RuntimeException('Backup manifest is incompatible with this database.');
            }

            if ($database) {
                $this->restoreDatabase($temp.'/extracted/'.$manifest['database_file']);
            }

            if ($files) {
                $this->restoreFiles($temp.'/extracted/files', $manifest['files'] ?? []);
            }
        } finally {
            File::deleteDirectory($temp);
        }
    }

    private function dumpDatabase(string $work): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $database = $connection->getConfig('database');

            if (! is_string($database) || ! is_file($database)) {
                throw new RuntimeException('SQLite backup requires a file-based database.');
            }

            $destination = $work.'/database.sqlite';
            copy($database, $destination);

            return 'database.sqlite';
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported backup database driver: {$driver}");
        }

        $destination = $work.'/database.sql';
        $config = $connection->getConfig();
        $command = [
            (string) config('backup.mysql_dump_binary', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
        ];

        if (! empty($config['unix_socket'])) {
            $command[] = '--socket='.$config['unix_socket'];
        }

        $command[] = (string) $config['database'];
        $result = Process::timeout(300)
            ->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException('Database dump failed: '.trim($result->errorOutput()));
        }

        file_put_contents($destination, $result->output());

        return 'database.sql';
    }

    /**
     * @return array<int, string>
     */
    private function copyFiles(string $destination): array
    {
        $files = [];

        foreach ((array) config('backup.files', []) as $source) {
            if (! is_dir($source)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                $relative = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
                $target = $destination.DIRECTORY_SEPARATOR.$relative;
                File::ensureDirectoryExists(dirname($target));
                copy($path, $target);
                $files[] = $relative;
            }
        }

        return $files;
    }

    private function restoreDatabase(string $source): void
    {
        if (! is_file($source)) {
            throw new RuntimeException('Database dump not found in backup.');
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $database = $connection->getConfig('database');

            if (! is_string($database)) {
                throw new RuntimeException('SQLite restore target is invalid.');
            }

            copy($source, $database);
            DB::purge();

            return;
        }

        $config = $connection->getConfig();
        $stream = fopen($source, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Cannot read database dump.');
        }

        try {
            $command = [
                (string) config('backup.mysql_binary', 'mysql'),
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--user='.$config['username'],
            ];

            if (! empty($config['unix_socket'])) {
                $command[] = '--socket='.$config['unix_socket'];
            }

            $command[] = (string) $config['database'];
            $result = Process::timeout(300)
                ->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
                ->input($stream)
                ->run($command);
            $this->throwIfFailed($result, 'Database restore failed.');
            DB::purge();
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<int, string>  $files
     */
    private function restoreFiles(string $source, array $files): void
    {
        foreach ($files as $relative) {
            $normalized = str_replace('\\', '/', (string) $relative);

            if ($normalized === '' || str_starts_with($normalized, '/') || in_array('..', explode('/', $normalized), true)) {
                throw new RuntimeException('Unsafe file path in backup manifest.');
            }

            $from = $source.DIRECTORY_SEPARATOR.$normalized;
            $to = base_path($normalized);

            if (! is_file($from)) {
                throw new RuntimeException("Backup file missing: {$normalized}");
            }

            File::ensureDirectoryExists(dirname($to));
            copy($from, $to);
        }
    }

    private function applyRetention(string $backupPath): void
    {
        $days = max(1, (int) config('backup.retention_days', 30));
        $cutoff = now()->subDays($days)->getTimestamp();

        foreach (glob($backupPath.'/*.bak.enc') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                @unlink($file.'.meta.json');
            }
        }
    }

    private function throwIfFailed(ProcessResult $result, string $message): void
    {
        if ($result->failed()) {
            throw new RuntimeException($message.' '.trim($result->errorOutput()));
        }
    }
}
