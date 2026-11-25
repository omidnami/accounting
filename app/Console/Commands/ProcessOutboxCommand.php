<?php

namespace App\Console\Commands;

use App\Events\DomainEventDispatched;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Throwable;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process {--limit=100 : Maximum events per run}';

    protected $description = 'Dispatch durable domain events with retry and backoff';

    public function handle(): int
    {
        $now = now();
        $timeout = now()->subSeconds(max(1, (int) config('outbox.processing_timeout', 300)));
        OutboxEvent::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', $timeout)
            ->update([
                'status' => 'pending',
                'available_at' => $now,
                'last_error' => 'Processing lease expired',
            ]);
        $events = OutboxEvent::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(min(500, max(1, (int) $this->option('limit'))))
            ->lockForUpdate()
            ->get();
        $failed = 0;

        foreach ($events as $event) {
            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
                'last_error' => null,
            ])->save();

            try {
                Event::dispatch(new DomainEventDispatched($event));
                $event->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $maxAttempts = max(1, (int) config('outbox.max_attempts', 5));
                $exhausted = $event->attempts >= $maxAttempts;
                $event->forceFill([
                    'status' => $exhausted ? 'failed' : 'pending',
                    'available_at' => $exhausted
                        ? $event->available_at
                        : now()->addSeconds(max(1, (int) config('outbox.retry_seconds', 60))),
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ])->save();
                $failed++;
            }
        }

        $this->info("Processed {$events->count()} domain event(s); {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
