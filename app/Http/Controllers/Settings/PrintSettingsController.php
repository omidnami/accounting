<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InvoicePrintFormat;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Printing\PrintSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
