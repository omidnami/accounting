<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InvoicePrintFormat;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PrintStation;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Printing\PrintSettingsService;
use App\Services\Printing\PrintStationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PrintSettingsController extends Controller
{
    public function edit(
        Request $request,
        PrintSettingsService $printSettings,
        BranchContextService $branches,
    ): Response {
        abort_unless($request->user()?->canManageStoreSettings(), 403);

        $store = $this->mainStore();
        $branch = $branches->active($request->user(), $store);

        return Inertia::render('settings/Print', [
            'settings' => $printSettings->forBranch($branch),
            'stations' => PrintStation::query()
                ->where('store_id', $store->id)
                ->where('branch_id', $branch->id)
                ->orderBy('name')
                ->get()
                ->map(fn (PrintStation $station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'platform' => $station->platform,
                    'status' => $station->status,
                    'last_seen_at' => $station->last_seen_at?->toIso8601String(),
                    'revoked_at' => $station->revoked_at?->toIso8601String(),
                    'connection_status' => match (true) {
                        $station->last_seen_at === null => 'never-seen',
                        $station->isOnline() => 'online',
                        default => 'offline',
                    },
                ])->all(),
        ]);
    }

    public function update(
        Request $request,
        PrintSettingsService $printSettings,
        BranchContextService $branches,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageStoreSettings(), 403);

        $store = $this->mainStore();
        $branch = $branches->active($request->user(), $store);
        $validated = $request->validate([
            'format' => ['required', Rule::enum(InvoicePrintFormat::class)],
            'copies' => ['required', 'integer', 'between:1,5'],
            'header_note' => ['nullable', 'string', 'max:255'],
        ]);

        $printSettings->update($branch, [
            'format' => InvoicePrintFormat::from($validated['format']),
            'copies' => (int) $validated['copies'],
            'header_note' => isset($validated['header_note'])
                ? trim((string) $validated['header_note']) ?: null
                : null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'تنظیمات چاپ فروشگاه ذخیره شد.',
        ]);

        return to_route('print-settings.edit');
    }

    public function rotateStation(
        Request $request,
        PrintStation $station,
        PrintStationService $stations,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageStoreSettings(), 403);
        $store = $this->mainStore();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        $station = $this->scopedStation($station, $store, $branch);

        try {
            $token = $stations->rotate($station);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['station' => $exception->getMessage()]);
        }

        Inertia::flash('print_station_token', [
            'station_id' => $station->id,
            'token' => $token,
        ]);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'توکن جدید Print Agent صادر شد.',
        ]);

        return to_route('print-settings.edit');
    }

    public function revokeStation(
        Request $request,
        PrintStation $station,
        PrintStationService $stations,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageStoreSettings(), 403);
        $store = $this->mainStore();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        $station = $this->scopedStation($station, $store, $branch);

        try {
            $stations->revoke($station);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['station' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Print Agent ابطال شد.',
        ]);

        return to_route('print-settings.edit');
    }

    private function scopedStation(PrintStation $station, Store $store, Branch $branch): PrintStation
    {
        abort_unless(
            (int) $station->store_id === (int) $store->id
            && (int) $station->branch_id === (int) $branch->id,
            404,
        );

        return $station;
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
