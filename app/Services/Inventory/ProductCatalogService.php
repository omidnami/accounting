<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type ProductCatalogRow array{
 *     id: int,
 *     code: string,
 *     barcode: string|null,
 *     name: string,
 *     unit: string,
 *     purchase_price: string,
 *     sale_price: string,
 *     tax_rate: string,
 *     reorder_level: string,
 *     is_active: bool,
 *     total_quantity: string,
 *     average_cost: string,
 *     inventory_value: string,
 *     stock_status: string
 * }
 */
class ProductCatalogService
{
    /**
     * @param  array{search?: string|null, stock_status?: string|null, status?: string|null, warehouse_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, ProductCatalogRow>
     */
    public function paginate(int $storeId, array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $stockStatus = $filters['stock_status'] ?? null;
        $status = $filters['status'] ?? null;
        $stockSubquery = $this->stockSubquery($storeId, $warehouseId);
        $valueSubquery = $this->valueSubquery($storeId, $warehouseId);

        return Product::query()
            ->select('products.*')
            ->selectSub($stockSubquery, 'total_quantity')
            ->selectSub($valueSubquery, 'inventory_value')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('barcode', 'like', $term);
                });
            })
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->when($stockStatus === 'in_stock', function (Builder $query) use ($storeId, $warehouseId): void {
                $query->whereExists($this->positiveStockSubquery($storeId, $warehouseId));
            })
            ->when($stockStatus === 'out_of_stock', function (Builder $query) use ($storeId, $warehouseId): void {
                $query->whereNotExists($this->positiveStockSubquery($storeId, $warehouseId));
            })
            ->when($stockStatus === 'low_stock', function (Builder $query) use ($storeId, $warehouseId): void {
                $query->whereExists($this->lowStockSubquery($storeId, $warehouseId));
            })
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Product $product): array {
                $quantity = Decimal::normalize(
                    $product->getAttribute('total_quantity') ?? 0,
                    Decimal::QUANTITY_SCALE,
                );
                $value = Decimal::normalize(
                    $product->getAttribute('inventory_value') ?? 0,
                    Decimal::MONEY_SCALE,
                );
                $averageCost = Decimal::isZero($quantity)
                    ? '0.0000'
                    : Decimal::divide($value, $quantity, Decimal::UNIT_COST_SCALE);
                $stockStatus = $this->stockStatus($quantity, $product->reorder_level);

                return [
                    'id' => $product->id,
                    'code' => $product->code,
                    'barcode' => $product->barcode,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'purchase_price' => $product->purchase_price,
                    'sale_price' => $product->sale_price,
                    'tax_rate' => $product->tax_rate,
                    'reorder_level' => $product->reorder_level,
                    'is_active' => $product->is_active,
                    'total_quantity' => $quantity,
                    'average_cost' => $averageCost,
                    'inventory_value' => $value,
                    'stock_status' => $stockStatus,
                ];
            });
    }

    /**
     * @return array{total: int, in_stock: int, low_stock: int, out_of_stock: int, inventory_value: string}
     */
    public function summary(int $storeId, ?int $warehouseId = null): array
    {
        $base = Product::query();
        $inStock = clone $base;
        $lowStock = clone $base;
        $outOfStock = clone $base;
        $inStock->whereExists($this->positiveStockSubquery($storeId, $warehouseId));
        $lowStock->whereExists($this->lowStockSubquery($storeId, $warehouseId));
        $outOfStock->whereNotExists($this->positiveStockSubquery($storeId, $warehouseId));
        $inventoryValue = InventoryBalance::query()
            ->where('store_id', $storeId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->sum('inventory_value');

        return [
            'total' => (clone $base)->count(),
            'in_stock' => $inStock->count(),
            'low_stock' => $lowStock->count(),
            'out_of_stock' => $outOfStock->count(),
            'inventory_value' => Decimal::normalize($inventoryValue, Decimal::MONEY_SCALE),
        ];
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function positiveStockSubquery(int $storeId, ?int $warehouseId): Builder
    {
        return InventoryBalance::query()
            ->selectRaw('1')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('inventory_balances.warehouse_id', $warehouseId))
            ->groupBy('inventory_balances.product_id')
            ->havingRaw('SUM(inventory_balances.quantity) > 0');
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function lowStockSubquery(int $storeId, ?int $warehouseId): Builder
    {
        return InventoryBalance::query()
            ->selectRaw('1')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('inventory_balances.warehouse_id', $warehouseId))
            ->groupBy('inventory_balances.product_id')
            ->havingRaw('SUM(inventory_balances.quantity) > 0')
            ->havingRaw('SUM(inventory_balances.quantity) <= products.reorder_level');
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function stockSubquery(int $storeId, ?int $warehouseId): Builder
    {
        return InventoryBalance::query()
            ->selectRaw('COALESCE(SUM(inventory_balances.quantity), 0)')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('inventory_balances.warehouse_id', $warehouseId));
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function valueSubquery(int $storeId, ?int $warehouseId): Builder
    {
        return InventoryBalance::query()
            ->selectRaw('COALESCE(SUM(inventory_balances.inventory_value), 0)')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('inventory_balances.warehouse_id', $warehouseId));
    }

    private function stockStatus(string $quantity, string $reorderLevel): string
    {
        if (Decimal::isZero($quantity)) {
            return 'out_of_stock';
        }

        if (Decimal::compare($quantity, $reorderLevel, Decimal::QUANTITY_SCALE) <= 0) {
            return 'low_stock';
        }

        return 'in_stock';
    }
}
