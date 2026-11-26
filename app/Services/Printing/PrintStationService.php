<?php

namespace App\Services\Printing;

use App\Models\PrintEvent;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Services\Audit\AuditLogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrintStationService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function rotate(PrintStation $station): string
    {
        return DB::transaction(function () use ($station): string {
            $locked = PrintStation::query()
                ->whereKey($station->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'active' || $locked->revoked_at !== null) {
                throw new DomainException('چرخش توکن فقط برای Print Agent فعال امکان‌پذیر است.');
            }

            $token = Str::random(64);
            $oldLastSeenAt = $locked->last_seen_at?->toDateTimeString();
            $locked->forceFill([
                'token_hash' => hash('sha256', $token),
                'last_seen_at' => null,
            ])->save();

            $this->auditLogs->record(
                'rotated',
                $locked,
                ['last_seen_at' => $oldLastSeenAt],
                ['last_seen_at' => null],
                'چرخش توکن Print Agent',
            );

            return $token;
        }, attempts: 3);
    }

    public function revoke(PrintStation $station): void
    {
        DB::transaction(function () use ($station): void {
            $locked = PrintStation::query()
                ->whereKey($station->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'revoked' && $locked->revoked_at !== null) {
                return;
            }

            if ($locked->status !== 'active' || $locked->revoked_at !== null) {
                throw new DomainException('این Print Agent قابل ابطال نیست.');
            }

            $revokedAt = now();
            $locked->forceFill([
                'status' => 'revoked',
                'revoked_at' => $revokedAt,
            ])->save();

            $jobs = PrintJob::query()
                ->where('print_station_id', $locked->id)
                ->whereIn('status', ['queued', 'claimed'])
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $job->forceFill([
                    'status' => 'failed',
                    'last_error' => 'Print station revoked',
                ])->save();

                PrintEvent::query()->create([
                    'print_job_id' => $job->id,
                    'print_station_id' => $locked->id,
                    'event' => 'failed',
                    'message' => 'Print station revoked',
                ]);
            }

            $this->auditLogs->record(
                'revoked',
                $locked,
                ['status' => 'active', 'revoked_at' => null],
                ['status' => 'revoked', 'revoked_at' => $revokedAt->toDateTimeString()],
                'ابطال Print Agent',
            );
        }, attempts: 3);
    }
}
