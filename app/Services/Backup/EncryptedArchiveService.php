<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class EncryptedArchiveService
{
    /**
     * @return array{version: int, algorithm: string, hmac: string}
     */
    public function encrypt(string $source, string $destination, string $passphrase): array
    {
        $macKey = hash_hkdf('sha256', $passphrase, 32, 'accounting-backup-mac');
        $result = Process::timeout(300)
            ->env(['BACKUP_PASSPHRASE' => $passphrase])
            ->run([
                'openssl',
                'enc',
                '-aes-256-cbc',
                '-salt',
                '-pbkdf2',
                '-iter',
                '200000',
                '-pass',
                'env:BACKUP_PASSPHRASE',
                '-in',
                $source,
                '-out',
                $destination,
            ]);
        $this->throwIfFailed($result, 'Backup encryption failed.');
        $meta = [
            'version' => 1,
            'algorithm' => 'aes-256-cbc-pbkdf2',
            'hmac' => $this->hmac($destination, $macKey),
        ];
        file_put_contents($destination.'.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $meta;
    }

    public function decrypt(string $source, string $destination, string $passphrase): void
    {
        $metaPath = $source.'.meta.json';

        if (! is_file($metaPath)) {
            throw new RuntimeException('Backup metadata not found.');
        }

        $meta = json_decode((string) file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
        $macKey = hash_hkdf('sha256', $passphrase, 32, 'accounting-backup-mac');

        if (($meta['version'] ?? null) !== 1
            || ($meta['algorithm'] ?? null) !== 'aes-256-cbc-pbkdf2'
            || ! is_string($meta['hmac'] ?? null)
            || ! hash_equals($meta['hmac'], $this->hmac($source, $macKey))) {
            throw new RuntimeException('Backup integrity check failed.');
        }

        $result = Process::timeout(300)
            ->env(['BACKUP_PASSPHRASE' => $passphrase])
            ->run([
                'openssl',
                'enc',
                '-d',
                '-aes-256-cbc',
                '-pbkdf2',
                '-iter',
                '200000',
                '-pass',
                'env:BACKUP_PASSPHRASE',
                '-in',
                $source,
                '-out',
                $destination,
            ]);
        $this->throwIfFailed($result, 'Backup decryption failed.');
    }

    private function hmac(string $path, string $key): string
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Cannot read backup file.');
        }

        try {
            $context = hash_init('sha256', HASH_HMAC, $key);
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function throwIfFailed(ProcessResult $result, string $message): void
    {
        if ($result->failed()) {
            throw new RuntimeException($message.' '.trim($result->errorOutput()));
        }
    }
}
