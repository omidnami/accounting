<?php

namespace App\Services\Purchasing;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Decimal;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;

class PurchaseLookupService
{
    /**
     * @return array<int, array{
     *     id: int, code: string, name: string, phone: string|null,
     *     payment_terms: string|null, lead_time_days: int|null
     * }>
     */
    public function suppliers(int $storeId, string $search): array
    {
        $search = trim(Digits::toLatin($search));

        return Supplier::query()
            ->with('party:id,code,name,phone,is_active')
            ->where('is_active', true)
            ->whereHas('party', function (Builder $query) use ($storeId, $search): void {
                $query
                    ->where('store_id', $storeId)
                    ->where('is_active', true)
                    ->when($search !== '', function (Builder $query) use ($search): void {
                        $term = $this->likeTerm($search);
                        $query->where(function (Builder $query) use ($term): void {
                            $query
                                ->where('code', 'like', $term)
                                ->orWhere('name', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhere('national_id', 'like', $term);
                        });
                    });
            })
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'code' => $supplier->party->code,
                'name' => $supplier->party->name,
                'phone' => $supplier->party->phone,
                'payment_terms' => $supplier->payment_terms,
                'lead_time_days' => $supplier->lead_time_days,
            ])
            ->all();
    }

    /**
     * @return array<int, array{
     *     id: int, code: string, barcode: string|null, name: string, unit: string,
     *     purchase_price: string, tax_rate: string, stock_quantity: string,
     *     track_batches: bool, track_serials: bool
     * }>
     */
    public function products(int $storeId, int $warehouseId, string $search): array
    {
        $search = trim(Digits::toLatin($search));
        $stock = InventoryBalance::query()
            ->selectRaw('COALESCE(SUM(inventory_balances.quantity), 0)')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->where('inventory_balances.warehouse_id', $warehouseId);

        return Product::query()
            ->select('products.*')
            ->selectSub($stock, 'stock_quantity')
            ->where('is_active', true)
            ->whereDoesntHave('variants')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = $this->likeTerm($search);
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('barcode', 'like', $term);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'variant_color' => $product->variant_color,
                'variant_size' => $product->variant_size,
                'unit' => $product->unit,
                'purchase_price' => $product->purchase_price,
                'tax_rate' => $product->tax_rate,
                'stock_quantity' => Decimal::normalize(
                    $product->getAttribute('stock_quantity') ?? 0,
                    Decimal::QUANTITY_SCALE,
                ),
                'track_batches' => $product->track_batches,
                'track_serials' => $product->track_serials,
            ])
            ->all();
    }

    private function likeTerm(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }
}
