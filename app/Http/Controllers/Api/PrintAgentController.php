<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintEvent;
use App\Models\PrintJob;
use App\Models\PrintStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintAgentController extends Controller
{
    public function claim(Request $request): JsonResponse
    {
        $station = $this->station($request);
        $job = DB::transaction(function () use ($station): ?PrintJob {
            $lockedStation = PrintStation::query()
                ->whereKey($station->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedStation->status === 'active' && $lockedStation->revoked_at === null,
                401,
            );

            $now = now();
            $leaseExpiredAt = $now->subSeconds(
                max(1, (int) config('print_agent.lease_seconds', 180)),
            );
            $maxAttempts = max(1, (int) config('print_agent.max_attempts', 3));

            PrintJob::query()
                ->where('print_station_id', $lockedStation->id)
                ->where('status', 'queued')
                ->where('expires_at', '<', $now)
                ->update(['status' => 'failed', 'last_error' => 'Job expired']);

            $expiredClaims = PrintJob::query()
                ->where('print_station_id', $lockedStation->id)
                ->where('status', 'claimed')
                ->where('claimed_at', '<', $leaseExpiredAt)
                ->lockForUpdate()
                ->get();

            foreach ($expiredClaims as $expired) {
                $exhausted = $expired->attempts >= $maxAttempts;
                $expired->forceFill([
                    'status' => $exhausted ? 'failed' : 'queued',
                    'claimed_at' => null,
                    'last_error' => $exhausted
                        ? 'Print agent lease expired after maximum attempts'
                        : 'Print agent lease expired',
                ])->save();

                PrintEvent::query()->create([
                    'print_job_id' => $expired->id,
                    'print_station_id' => $lockedStation->id,
                    'event' => $exhausted ? 'failed' : 'reclaimed',
                    'message' => $expired->last_error,
                ]);
            }

            $job = PrintJob::query()
                ->where('print_station_id', $lockedStation->id)
                ->where('status', 'queued')
                ->where('expires_at', '>', $now)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $job->forceFill([
                'status' => 'claimed',
                'claimed_at' => $now,
                'attempts' => $job->attempts + 1,
            ])->save();
            PrintEvent::query()->create([
                'print_job_id' => $job->id,
                'print_station_id' => $lockedStation->id,
                'event' => 'claimed',
            ]);

            return $job;
        });
        $station->update(['last_seen_at' => now()]);

        return response()->json(['job' => $job]);
    }

    public function heartbeat(Request $request, PrintJob $job): JsonResponse
    {
        $station = $this->station($request);

        return DB::transaction(function () use ($station, $job): JsonResponse {
            $locked = PrintJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $locked->print_station_id === (int) $station->id, 404);
            abort_unless($locked->status === 'claimed', 409);

            $locked->forceFill(['claimed_at' => now()])->save();
            $station->update(['last_seen_at' => now()]);

            return response()->json(['ok' => true]);
        });
    }

    public function complete(
        Request $request,
        PrintJob $job,
    ): JsonResponse {
        $station = $this->station($request);
        $validated = $request->validate([
            'status' => ['required', 'in:printed,failed'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($station, $job, $validated): JsonResponse {
            $locked = PrintJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $locked->print_station_id === (int) $station->id, 404);

            // Network retries are common: repeating the same completion must
            // succeed without emitting a second event or changing the result.
            if (in_array($locked->status, ['printed', 'failed'], true)) {
                abort_unless($locked->status === $validated['status'], 409);

                return response()->json(['ok' => true]);
            }

            abort_unless($locked->status === 'claimed', 409);
            $locked->forceFill([
                'status' => $validated['status'],
                'printed_at' => $validated['status'] === 'printed' ? now() : null,
                'last_error' => $validated['status'] === 'failed'
                    ? ($validated['message'] ?? 'Print failed')
                    : null,
            ])->save();
            PrintEvent::query()->create([
                'print_job_id' => $locked->id,
                'print_station_id' => $station->id,
                'event' => $validated['status'],
                'message' => $validated['message'] ?? null,
            ]);
            $station->update(['last_seen_at' => now()]);

            return response()->json(['ok' => true]);
        });
    }

    private function station(Request $request): PrintStation
    {
        $token = $request->bearerToken();
        abort_if($token === null || $token === '', 401);
        $station = PrintStation::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();
        abort_if($station === null, 401);

        return $station;
    }
}
