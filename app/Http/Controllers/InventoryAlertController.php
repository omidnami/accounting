<?php

namespace App\Http\Controllers;

use App\Models\InventoryAlert;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    public function acknowledge(
        Request $request,
        InventoryAlert $alert,
        BranchContextService $branches,
    ): RedirectResponse {
        abort_unless($request->user()?->canViewInventoryReports(), 403);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = $branches->active($request->user(), $store);
        abort_unless(
            (int) $alert->store_id === (int) $store->id
            && (int) $alert->branch_id === (int) $branch->id,
            404,
        );
        $alert->update(['acknowledged_at' => now()]);

        return back();
    }
}
