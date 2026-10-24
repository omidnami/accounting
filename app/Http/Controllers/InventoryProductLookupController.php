<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Branches\BranchContextService;
use App\Services\Inventory\InventoryProductLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryProductLookupController extends Controller
{
    public function __invoke(
        Request $request,
        InventoryProductLookupService $lookup,
    ): JsonResponse {
        $user = $request->user();
        abort_unless(
            $user?->canManageStockCounts()
            || $user?->canTransferInventory()
            || $user?->canViewInventoryReports(),
            403,
        );
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = app(BranchContextService::class)->active($user, $store);
        $validated = $request->validate([
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('store_id', $store->id)
                    ->where('branch_id', $branch->id),
            ],
            'search' => ['nullable', 'string', 'max:100'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);
        $warehouseId = isset($validated['warehouse_id'])
            ? (int) $validated['warehouse_id']
            : null;

        if ($warehouseId !== null) {
            Warehouse::query()
                ->whereKey($warehouseId)
                ->where('store_id', $store->id)
                ->where('branch_id', $branch->id)
                ->firstOrFail();
        }

        return response()->json([
            'data' => $lookup->products(
                $store->id,
                $warehouseId,
                (string) ($validated['search'] ?? ''),
                (bool) ($validated['include_inactive'] ?? false),
            ),
        ]);
    }
}
