<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Support\Decimal;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;

class InventoryProductLookupService
{
    /**
     * @return array<int, array{
     *     id: int, code: string, barcode: string|null, name: string, unit: string,
     *     is_active: bool, stock_quantity: string
     * }>
     */
    public function products(
        int $storeId,
        ?int $warehouseId,
        string $search,
        bool $includeInactive = false,
    ): array {
        $search = trim(Digits::toLatin($search));
        $stock = InventoryBalance::query()
            ->selectRaw('COALESCE(SUM(inventory_balances.quantity), 0)')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->when(
                $warehouseId !== null,
                fn (Builder $query) => $query->where('inventory_balances.warehouse_id', $warehouseId),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );

        return Product::query()
            ->select('products.*')
            ->selectSub($stock, 'stock_quantity')
            ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn (Builder $query) => $query
                    ->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('barcode', 'like', $term));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'unit' => $product->unit,
                'is_active' => $product->is_active,
                'stock_quantity' => Decimal::normalize(
                    $product->getAttribute('stock_quantity') ?? 0,
                    Decimal::QUANTITY_SCALE,
                ),
            ])
            ->all();
    }
}
