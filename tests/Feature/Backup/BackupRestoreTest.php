<?php

use App\Services\Backup\BackupService;
use App\Services\Backup\EncryptedArchiveService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('creates an encrypted backup and restores both database and files', function () {
    $originalDefault = config('database.default');
    $directory = sys_get_temp_dir().'/accounting-backup-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    $database = $directory.'/database.sqlite';
    touch($database);
    $files = storage_path('app/private/backup-restore-test');
    File::ensureDirectoryExists($files);
    File::put($files.'/sample.txt', 'original-file');

    config([
        'database.default' => 'backup_test',
        'database.connections.backup_test' => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'backup.path' => $directory.'/backups',
        'backup.passphrase' => 'test-backup-passphrase',
        'backup.files' => [$files],
        'backup.retention_days' => 30,
    ]);
    DB::purge('backup_test');
    Artisan::call('migrate', ['--database' => 'backup_test', '--force' => true]);
    DB::connection('backup_test')->table('stores')->insert([
        'code' => 'BACKUP-STORE',
        'name' => 'Original store',
        'currency' => 'IRR',
        'timezone' => 'Asia/Tehran',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $backup = app(BackupService::class)->create();

    expect(is_file($backup))->toBeTrue()
        ->and(is_file($backup.'.meta.json'))->toBeTrue()
        ->and(file_get_contents($backup))->not->toContain('Original store');

    DB::connection('backup_test')->table('stores')->where('code', 'BACKUP-STORE')->update(['name' => 'Changed store']);
    File::put($files.'/sample.txt', 'changed-file');

    app(BackupService::class)->restore($backup, database: true, files: true);

    expect(DB::connection('backup_test')->table('stores')->where('code', 'BACKUP-STORE')->value('name'))
        ->toBe('Original store')
        ->and(File::get($files.'/sample.txt'))->toBe('original-file');

    config(['database.default' => $originalDefault]);
    DB::purge('backup_test');
    File::deleteDirectory($directory);
    File::deleteDirectory($files);
});

it('refuses to decrypt a tampered backup', function () {
    $directory = sys_get_temp_dir().'/accounting-backup-tamper-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/payload.txt', 'secret');
    $encrypted = $directory.'/payload.enc';
    app(EncryptedArchiveService::class)
        ->encrypt($directory.'/payload.txt', $encrypted, 'correct-passphrase');
    file_put_contents($encrypted, 'tampered', FILE_APPEND);

    expect(fn () => app(EncryptedArchiveService::class)
        ->decrypt($encrypted, $directory.'/decrypted.txt', 'correct-passphrase'))
        ->toThrow(RuntimeException::class, 'integrity check failed');

    File::deleteDirectory($directory);
});
