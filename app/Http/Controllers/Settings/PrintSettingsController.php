<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InvoicePrintFormat;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Store;
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
    ): Response {
        abort_unless($request->user()?->canManageStoreSettings(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('store_id', $store->id),
            ],
        ]);
        $branches = $store->branches()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default', 'is_active']);
        $branch = isset($validated['branch_id'])
            ? $branches->firstWhere('id', (int) $validated['branch_id'])
            : ($branches->firstWhere('is_default', true) ?? $branches->first());

        abort_if($branch === null, 404);

        return Inertia::render('settings/Print', [
            'branches' => $branches,
            'selected_branch_id' => $branch->id,
            'settings' => $printSettings->forBranch($branch),
        ]);
    }

    public function update(
        Request $request,
        PrintSettingsService $printSettings,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageStoreSettings(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('store_id', $store->id),
            ],
            'format' => ['required', Rule::enum(InvoicePrintFormat::class)],
            'copies' => ['required', 'integer', 'between:1,5'],
            'header_note' => ['nullable', 'string', 'max:255'],
        ]);
        $branch = Branch::query()
            ->where('store_id', $store->id)
            ->findOrFail((int) $validated['branch_id']);

        $printSettings->update($branch, [
            'format' => InvoicePrintFormat::from($validated['format']),
            'copies' => (int) $validated['copies'],
            'header_note' => isset($validated['header_note'])
                ? trim((string) $validated['header_note']) ?: null
                : null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'تنظیمات چاپ شعبه ذخیره شد.',
        ]);

        return to_route('print-settings.edit', [
            'branch_id' => $branch->id,
        ]);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
