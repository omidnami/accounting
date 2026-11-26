<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\InventoryBatchBalance;
use App\Models\Product;
use App\Support\BusinessClock;
use App\Support\Decimal;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;

class SalesCheckoutLookupService
{
    public function __construct(
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @return array<int, array{
     *     id: int, code: string, name: string, phone: string|null,
     *     credit_limit: string, payment_terms: string|null,
     *     is_active: bool, status_label: string
     * }>
     */
    public function customers(int $storeId, string $search, bool $includeInactive = false): array
    {
        $search = trim(Digits::toLatin($search));

        return Customer::query()
            ->with('party:id,code,name,phone,is_active')
            ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('party', function (Builder $query) use ($storeId, $search, $includeInactive): void {
                $query
                    ->where('store_id', $storeId)
                    ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))
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
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'code' => $customer->party->code,
                'name' => $customer->party->name,
                'phone' => $customer->party->phone,
                'credit_limit' => $customer->credit_limit,
                'payment_terms' => $customer->payment_terms,
                'is_active' => $customer->is_active && $customer->party->is_active,
                'status_label' => $customer->is_active && $customer->party->is_active ? 'فعال' : 'غیرفعال',
            ])
            ->all();
    }

    /**
     * @return array<int, array{
     *     id: int, code: string, barcode: string|null, name: string, unit: string,
     *     sale_price: string, tax_rate: string, stock_quantity: string,
     *     track_batches: bool, track_serials: bool, image_url: string|null
     * }>
     */
    public function products(int $storeId, int $warehouseId, string $search, ?int $categoryId = null, int $limit = 20): array
    {
        $search = trim(Digits::toLatin($search));

        // A legacy cross-column collision must not let POS scanning add the first match.
        if ($search !== '' && $this->hasAmbiguousIdentifier($search, $categoryId)) {
            return [];
        }

        $stock = InventoryBalance::query()
            ->selectRaw('COALESCE(SUM(inventory_balances.quantity), 0)')
            ->whereColumn('inventory_balances.product_id', 'products.id')
            ->where('inventory_balances.store_id', $storeId)
            ->where('inventory_balances.warehouse_id', $warehouseId);
        $expired = InventoryBatchBalance::query()
            ->join('product_batches', 'product_batches.id', '=', 'inventory_batch_balances.product_batch_id')
            ->selectRaw('COALESCE(SUM(inventory_batch_balances.quantity), 0)')
            ->whereColumn('product_batches.product_id', 'products.id')
            ->where('inventory_batch_balances.store_id', $storeId)
            ->where('inventory_batch_balances.warehouse_id', $warehouseId)
            ->whereDate('product_batches.expiry_date', '<', $this->clock->today($storeId)->toDateString());

        return Product::query()
            ->select('products.*')
            ->selectSub($stock, 'stock_quantity')
            ->selectSub($expired, 'expired_quantity')
            ->where('is_active', true)
            ->whereDoesntHave('variants')
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = $this->likeTerm($search);
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('barcode', 'like', $term);
                });
            })
            ->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN code = ? THEN 1 ELSE 2 END', [$search, $search])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(min(20, max(1, $limit)))
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'image_url' => $product->imageUrl(),
                'name' => $product->name,
                'variant_color' => $product->variant_color,
                'variant_size' => $product->variant_size,
                'unit' => $product->unit,
                'sale_price' => $product->sale_price,
                'tax_rate' => $product->tax_rate,
                'item_type' => $product->item_type,
                'stock_quantity' => Decimal::normalize(
                    Decimal::subtract(
                        $product->getAttribute('stock_quantity') ?? 0,
                        $product->getAttribute('expired_quantity') ?? 0,
                        Decimal::QUANTITY_SCALE,
                    ),
                    Decimal::QUANTITY_SCALE,
                ),
                'track_batches' => $product->track_batches,
                'track_serials' => $product->track_serials,
            ])
            ->all();
    }

    private function hasAmbiguousIdentifier(string $identifier, ?int $categoryId): bool
    {
        return Product::query()
            ->where('is_active', true)
            ->whereDoesntHave('variants')
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->where(fn (Builder $query) => $query
                ->where('code', $identifier)
                ->orWhere('barcode', $identifier))
            ->limit(2)
            ->count() > 1;
    }

    private function likeTerm(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }
}
