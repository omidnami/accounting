<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\InventoryOpening;
use App\Models\Product;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Inventory\OpeningInventoryService;
use App\Support\BusinessClock;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpeningInventoryController extends Controller
{
    public function index(
        Request $request,
        OpeningInventoryService $openings,
        BusinessClock $clock,
    ): Response {
        abort_unless($request->user()?->canManageProducts(), 403);
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $year = $this->currentYear($store);
        $today = $clock->today($store)->toDateString();

        return Inertia::render('inventory-openings/Index', [
            'products' => Product::query()
                ->where('is_active', true)
                ->where('item_type', 'stock')
                ->whereDoesntHave('variants')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'purchase_price', 'track_serials'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'label' => $product->code.' — '.$product->name,
                    'unit' => $product->unit,
                    'unit_cost' => $product->purchase_price,
                    'track_serials' => $product->track_serials,
                ]),
            'warehouses' => $branch->warehouses()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'name']),
            'default_date' => max(
                $year->starts_on->toDateString(),
                min($year->ends_on->toDateString(), $today),
            ),
            'openings' => InventoryOpening::query()
                ->where('store_id', $store->id)
                ->where('branch_id', $branch->id)
                ->with('journalEntry:id,entry_number')
                ->withCount('items')
                ->latest('opening_date')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (InventoryOpening $opening): array => [
                    'id' => $opening->id,
                    'date' => $opening->opening_date->toDateString(),
                    'total' => $opening->total_cost,
                    'items_count' => $opening->items_count,
                    'entry_number' => $opening->journalEntry?->entry_number,
                ]),
            'reconciliation' => $openings->reconciliation(
                $store->id,
                $branch->id,
                $today,
            ),
        ]);
    }

    public function store(
        Request $request,
        OpeningInventoryService $openings,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageProducts(), 403);
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $year = $this->currentYear($store);
        $validated = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'warehouse_id' => [
                'required',
                'integer',
                'exists:warehouses,id',
            ],
            'opening_date' => [
                'required',
                'date',
                'after_or_equal:'.$year->starts_on->toDateString(),
                'before_or_equal:'.$year->ends_on->toDateString(),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'items.*.product_batch_id' => ['nullable', 'integer', 'exists:product_batches,id'],
            'items.*.serial_numbers' => ['nullable', 'array', 'max:500'],
            'items.*.serial_numbers.*' => ['string', 'max:120'],
        ]);
        $warehouse = Warehouse::query()
            ->whereKey((int) $validated['warehouse_id'])
            ->firstOrFail();

        try {
            $openings->open(
                $branch,
                $warehouse,
                $year,
                $validated['opening_date'],
                $validated['items'],
                $request->user(),
                $validated['submission_token'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['opening' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'موجودی افتتاحیه و سند متوازن آن ثبت شد.']);

        return to_route('inventory-openings.index');
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function defaultBranch(Store $store): Branch
    {
        return $store->defaultBranch;
    }

    private function currentYear(Store $store): FiscalYear
    {
        return $store->fiscalYears()->where('is_current', true)->firstOrFail();
    }
}
