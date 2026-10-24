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
 *     variant_color: string|null,
 *     variant_size: string|null,
 *     variants_count: int,
 *     category: array{id: int, code: string, name: string}|null,
 *     unit: string,
 *     purchase_price: string,
 *     sale_price: string,
 *     tax_rate: string,
 *     reorder_level: string,
 *     safety_stock: string,
 *     reorder_quantity: string,
 *     max_stock: string|null,
 *     suggested_order_quantity: string,
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
     * @param  array{search?: string|null, stock_status?: string|null, status?: string|null, warehouse_id?: int|null, category_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, ProductCatalogRow>
     */
    public function paginate(int $storeId, array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $stockStatus = $filters['stock_status'] ?? null;
        $status = $filters['status'] ?? null;
        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $stockSubquery = $this->stockSubquery($storeId, $warehouseId);
        $valueSubquery = $this->valueSubquery($storeId, $warehouseId);

        return Product::query()
            ->select('products.*')
            ->selectSub($stockSubquery, 'total_quantity')
            ->selectSub($valueSubquery, 'inventory_value')
            ->with(['category:id,code,name'])
            ->withCount('variants')
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
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
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
                $reorderThreshold = $product->stock_policy->value === 'min_max'
                    && Decimal::compare($product->safety_stock, $product->reorder_level, 3) > 0
                        ? $product->safety_stock
                        : $product->reorder_level;
                $reorderThreshold = Decimal::normalize($reorderThreshold, 3);
                $stockStatus = $this->stockStatus($quantity, $reorderThreshold);
                $suggestedOrder = $product->reorder_quantity;

                if ($product->max_stock !== null) {
                    $upToMaximum = Decimal::subtract(
                        $product->max_stock,
                        $quantity,
                        Decimal::QUANTITY_SCALE,
                    );
                    $suggestedOrder = Decimal::compare($upToMaximum, '0', 3) > 0
                        && Decimal::compare($upToMaximum, $suggestedOrder, 3) > 0
                            ? $upToMaximum
                            : $suggestedOrder;
                }
                $suggestedOrder = Decimal::normalize($suggestedOrder, 3);
                $category = $product->category === null
                    ? null
                    : [
                        'id' => $product->category->id,
                        'code' => $product->category->code,
                        'name' => $product->category->name,
                    ];

                return [
                    'id' => (int) $product->id,
                    'code' => $product->code,
                    'barcode' => $product->barcode,
                    'name' => $product->name,
                    'variant_color' => $product->variant_color,
                    'variant_size' => $product->variant_size,
                    'variants_count' => (int) $product->variants_count,
                    'category' => $category,
                    'unit' => $product->unit,
                    'purchase_price' => Decimal::normalize($product->purchase_price, 2),
                    'sale_price' => Decimal::normalize($product->sale_price, 2),
                    'tax_rate' => Decimal::normalize($product->tax_rate, 2),
                    'reorder_level' => Decimal::normalize($product->reorder_level, 3),
                    'safety_stock' => Decimal::normalize($product->safety_stock, 3),
                    'reorder_quantity' => Decimal::normalize($product->reorder_quantity, 3),
                    'max_stock' => $product->max_stock === null
                        ? null
                        : Decimal::normalize($product->max_stock, 3),
                    'suggested_order_quantity' => $suggestedOrder,
                    'is_active' => $product->is_active,
                    'total_quantity' => $quantity,
                    'average_cost' => $averageCost,
                    'inventory_value' => $value,
                    'stock_status' => (string) $stockStatus,
                ];
            });
    }

    /**
     * @return array{total: int, in_stock: int, low_stock: int, out_of_stock: int, inventory_value: string}
     */
    public function summary(
        int $storeId,
        ?int $warehouseId = null,
        ?int $categoryId = null,
    ): array {
        $base = Product::query()
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId));
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
            ->havingRaw(
                "SUM(inventory_balances.quantity) <= CASE WHEN products.stock_policy = 'min_max' AND products.safety_stock > products.reorder_level THEN products.safety_stock ELSE products.reorder_level END",
            );
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
