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
            PrintJob::query()
                ->where('print_station_id', $station->id)
                ->where('status', 'queued')
                ->where('expires_at', '<', now())
                ->update(['status' => 'failed', 'last_error' => 'Job expired']);
            $job = PrintJob::query()
                ->where('print_station_id', $station->id)
                ->where('status', 'queued')
                ->where('expires_at', '>', now())
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return null;
            }

            $job->forceFill([
                'status' => 'claimed',
                'claimed_at' => now(),
                'attempts' => $job->attempts + 1,
            ])->save();
            PrintEvent::query()->create([
                'print_job_id' => $job->id,
                'print_station_id' => $station->id,
                'event' => 'claimed',
            ]);

            return $job;
        });
        $station->update(['last_seen_at' => now()]);

        return response()->json(['job' => $job]);
    }

    public function complete(
        Request $request,
        PrintJob $job,
    ): JsonResponse {
        $station = $this->station($request);
        abort_unless((int) $job->print_station_id === (int) $station->id, 404);
        $validated = $request->validate([
            'status' => ['required', 'in:printed,failed'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($job->status === 'claimed', 409);
        $job->forceFill([
            'status' => $validated['status'],
            'printed_at' => $validated['status'] === 'printed' ? now() : null,
            'last_error' => $validated['status'] === 'failed'
                ? ($validated['message'] ?? 'Print failed')
                : null,
        ])->save();
        PrintEvent::query()->create([
            'print_job_id' => $job->id,
            'print_station_id' => $station->id,
            'event' => $validated['status'],
            'message' => $validated['message'] ?? null,
        ]);
        $station->update(['last_seen_at' => now()]);

        return response()->json(['ok' => true]);
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
