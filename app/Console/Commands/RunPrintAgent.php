<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class RunPrintAgent extends Command
{
    protected $signature = 'print-agent:run
        {--server= : Accounting system base URL}
        {--token= : Station token}
        {--printer= : OS printer name}
        {--interval=3 : Poll interval in seconds}
        {--once : Claim and process at most one job}';

    protected $description = 'Run the local outbound-only print agent';

    public function handle(): int
    {
        $server = rtrim($this->stringOption('server'), '/');
        $token = $this->stringOption('token');

        if ($server === '' || $token === '') {
            $this->error('--server and --token are required.');

            return self::FAILURE;
        }

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->post($server.'/api/print-agent/claim');

            if ($response->unauthorized()) {
                $this->error('Print station token was rejected.');

                return self::FAILURE;
            }

            if (! $response->successful()) {
                $this->warn('Claim failed: HTTP '.$response->status());
                sleep(max(1, (int) $this->option('interval')));

                continue;
            }

            $job = $response->json('job');

            if (! is_array($job)) {
                if ($this->option('once')) {
                    break;
                }

                sleep(max(1, (int) $this->option('interval')));

                continue;
            }

            try {
                $this->printJob($job);
                $result = ['status' => 'printed'];
            } catch (\Throwable $exception) {
                $result = [
                    'status' => 'failed',
                    'message' => mb_substr($exception->getMessage(), 0, 2000),
                ];
            }

            Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->post($server.'/api/print-agent/jobs/'.$job['id'].'/complete', $result)
                ->throw();
            $this->info("Job {$job['id']} reported as {$result['status']}.");
        } while (! $this->option('once'));

        return self::SUCCESS;
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
