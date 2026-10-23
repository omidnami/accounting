<?php

namespace App\Http\Controllers;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Http\Requests\InventoryCardexRequest;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\InventoryCardexReportService;
use Inertia\Inertia;
use Inertia\Response;

class InventoryCardexController extends Controller
{
    public function __invoke(
        InventoryCardexRequest $request,
        InventoryCardexReportService $reports,
    ): Response {
        $user = $request->user();
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $validated = $request->validated();
        $from = $validated['from'];
        $to = $validated['to'];
        $productId = isset($validated['product_id']) ? (int) $validated['product_id'] : null;
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;
        $movementType = isset($validated['movement_type'])
            ? InventoryMovementType::from($validated['movement_type'])
            : null;
        $direction = isset($validated['direction'])
            ? InventoryDirection::from($validated['direction'])
            : null;
        $report = $productId !== null && $warehouseId !== null
            ? $reports->report(
                storeId: $store->id,
                productId: $productId,
                warehouseId: $warehouseId,
                from: $from,
                to: $to,
                movementType: $movementType,
                direction: $direction,
                includeFinancials: $user->canViewInventoryCosts(),
                page: (int) ($validated['page'] ?? 1),
            )
            : null;

        return Inertia::render('inventory/cardex/Index', [
            'report' => $report,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'movement_type' => $movementType?->value,
                'direction' => $direction?->value,
            ],
            'products' => Product::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'is_active']),
            'warehouses' => $store->warehouses()
                ->orderByDesc('is_active')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_default', 'is_active']),
            'can_view_costs' => $user->canViewInventoryCosts(),
        ]);
    }
}
