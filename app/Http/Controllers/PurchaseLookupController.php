<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseLookupController extends Controller
{
    public function suppliers(Request $request, PurchaseLookupService $lookup): JsonResponse
    {
        abort_unless($request->user()?->canManagePurchases(), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => $lookup->suppliers(
                $this->mainStore()->id,
                (string) ($validated['search'] ?? ''),
            ),
        ]);
    }

    public function products(Request $request, PurchaseLookupService $lookup): JsonResponse
    {
        abort_unless($request->user()?->canManagePurchases(), 403);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $store = $this->mainStore();
        $warehouse = Warehouse::query()
            ->whereKey((int) $validated['warehouse_id'])
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'data' => $lookup->products(
                $store->id,
                $warehouse->id,
                (string) ($validated['search'] ?? ''),
            ),
        ]);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
