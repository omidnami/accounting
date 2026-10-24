<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryTransferRequest;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\InventoryTransferService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryTransferController extends Controller
{
    public function index(Request $request, InventoryTransferService $transfers): Response
    {
        abort_unless($request->user()?->canTransferInventory(), 403);
        $store = $this->mainStore();
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        return Inertia::render('inventory/transfers/Index', [
            'transfers' => $transfers->paginate(
                $store,
                [
                    'warehouse_id' => isset($validated['warehouse_id'])
                        ? (int) $validated['warehouse_id']
                        : null,
                ],
                $request->user()->canViewInventoryCosts(),
            ),
            'filters' => [
                'warehouse_id' => isset($validated['warehouse_id'])
                    ? (int) $validated['warehouse_id']
                    : null,
            ],
            'warehouses' => $this->warehouses($store),
            'can_view_costs' => $request->user()->canViewInventoryCosts(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canTransferInventory(), 403);
        $store = $this->mainStore();

        return Inertia::render('inventory/transfers/Create', [
            'warehouses' => $this->warehouses($store),
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'unit']),
            'can_view_costs' => $request->user()->canViewInventoryCosts(),
        ]);
    }

    public function store(
        InventoryTransferRequest $request,
        InventoryTransferService $transfers,
    ): RedirectResponse {
        try {
            $transfer = $transfers->transfer(
                $this->mainStore(),
                $request->validated(),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "انتقال {$transfer->transfer_number} ثبت شد.",
        ]);

        return to_route('inventory-transfers.index');
    }

    private function warehouses(Store $store): mixed
    {
        return $store->warehouses()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
