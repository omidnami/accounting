<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class RunPrintAgent extends Command
{
    protected $signature = 'print-agent:run
        {--server= : Accounting system base URL}
        {--token= : Station token}
        {--printer= : OS printer name}
        {--interval=3 : Poll interval in seconds}
        {--once : Claim and process at most one job}';

    protected $description = 'Run the local outbound-only print agent';

    private const TRANSPORT_ATTEMPTS = 4;

    public function handle(): int
    {
        $server = rtrim($this->stringOption('server'), '/');
        $token = $this->stringOption('token');

        if ($server === '' || $token === '') {
            $this->error('--server and --token are required.');

            return self::FAILURE;
        }

        if (! $this->isSafeServer($server)) {
            $this->error('HTTPS is required; plain HTTP is allowed only for localhost development.');

            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $consecutiveFailures = 0;

        do {
            try {
                $response = $this->client($server, $token)
                    ->post($server.'/api/print-agent/claim');
            } catch (Throwable $exception) {
                $this->warn('Claim failed: '.$exception->getMessage());

                if ($this->option('once')) {
                    return self::FAILURE;
                }

                $this->backoff(++$consecutiveFailures, $interval);

                continue;
            }

            if ($response->unauthorized()) {
                $this->error('Print station token was rejected.');

                return self::FAILURE;
            }

            if (! $response->successful()) {
                $this->warn('Claim failed: HTTP '.$response->status());

                if ($this->option('once')) {
                    return self::FAILURE;
                }

                $this->backoff(++$consecutiveFailures, $interval);

                continue;
            }

            $consecutiveFailures = 0;
            $job = $response->json('job');

            if (! is_array($job)) {
                if ($this->option('once')) {
                    break;
                }

                sleep($interval);

                continue;
            }

            $this->heartbeat($server, $token, $job);

            try {
                $this->printJob($job);
                $result = ['status' => 'printed'];
            } catch (Throwable $exception) {
                $result = [
                    'status' => 'failed',
                    'message' => mb_substr($exception->getMessage(), 0, 2000),
                ];
            }

            $this->heartbeat($server, $token, $job);

            if (! $this->reportCompletion($server, $token, $job, $result)) {
                return self::FAILURE;
            }

            $this->info("Job {$job['id']} reported as {$result['status']}.");
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function client(string $server, string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $result
     */
    private function reportCompletion(
        string $server,
        string $token,
        array $job,
        array $result,
    ): bool {
        for ($attempt = 1; $attempt <= self::TRANSPORT_ATTEMPTS; $attempt++) {
            try {
                $response = $this->client($server, $token)
                    ->post($server.'/api/print-agent/jobs/'.$job['id'].'/complete', $result);

                if ($response->successful()) {
                    return true;
                }

                // A reclaimed lease cannot be completed by the old agent.
                if ($response->status() === 409 || $response->unauthorized()) {
                    $this->warn('Completion rejected: HTTP '.$response->status());

                    return false;
                }

                $this->warn("Completion attempt {$attempt} failed: HTTP ".$response->status());
            } catch (Throwable $exception) {
                $this->warn("Completion attempt {$attempt} failed: ".$exception->getMessage());
            }

            if ($attempt < self::TRANSPORT_ATTEMPTS) {
                sleep(min(15, 2 ** ($attempt - 1)));
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function heartbeat(string $server, string $token, array $job): void
    {
        try {
            $this->client($server, $token)
                ->post($server.'/api/print-agent/jobs/'.$job['id'].'/heartbeat')
                ->throw();
        } catch (Throwable $exception) {
            // Best effort: completion remains idempotent and the server will
            // reclaim the job if this agent disappears.
            $this->warn('Heartbeat failed: '.$exception->getMessage());
        }
    }

    private function backoff(int $failures, int $interval): void
    {
        sleep(min(30, $interval * (2 ** min(5, max(0, $failures - 1)))));
    }

    private function isSafeServer(string $server): bool
    {
        $parts = parse_url($server);

        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower($parts['host']);

        return $scheme === 'https' || ($scheme === 'http' && in_array(
            $host,
            ['localhost', '127.0.0.1', '::1'],
            true,
        ));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function printJob(array $job): void
    {
        $html = view('printing.agent-receipt', [
            'payload' => $job['payload'] ?? [],
            'copies' => (int) ($job['copies'] ?? 1),
            'paperWidth' => (int) ($job['paper_width_mm'] ?? 80),
        ])->render();
        $file = tempnam(sys_get_temp_dir(), 'hsabyar-print-');

        if ($file === false) {
            throw new RuntimeException('Cannot create temporary print file.');
        }

        $pdf = $file.'.pdf';
        file_put_contents($file.'.html', $html);

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $command = sprintf(
                    'powershell -NoProfile -Command "Get-Content -Raw -LiteralPath %s | Out-Printer -Name %s"',
                    escapeshellarg($file.'.html'),
                    escapeshellarg($this->stringOption('printer')),
                );
            } else {
                $chrome = $this->findChrome();
                Process::timeout(30)->run([
                    $chrome,
                    '--headless',
                    '--disable-gpu',
                    '--no-sandbox',
                    '--print-to-pdf='.$pdf,
                    'file://'.$file.'.html',
                ])->throw();
                $printer = $this->stringOption('printer');
                $command = 'lp'
                    .($printer !== '' ? ' -d '.escapeshellarg($printer) : '')
                    .' -n '.(int) ($job['copies'] ?? 1)
                    .' '.escapeshellarg($pdf);
            }

            Process::timeout(30)->run($command)->throw();
        } finally {
            @unlink($file);
            @unlink($file.'.html');
            @unlink($pdf);
        }
    }

    private function findChrome(): string
    {
        foreach (['google-chrome', 'chromium', 'chromium-browser'] as $binary) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($binary)));

            if ($path !== '') {
                return $path;
            }
        }

        throw new RuntimeException('Chrome/Chromium is required by the local print agent.');
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
