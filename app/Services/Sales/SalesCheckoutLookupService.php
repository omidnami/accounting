<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Support\Decimal;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;

class SalesCheckoutLookupService
{
    /**
     * @return array<int, array{
     *     id: int, code: string, name: string, phone: string|null,
     *     credit_limit: string, payment_terms: string|null
     * }>
     */
    public function customers(int $storeId, string $search): array
    {
        $search = trim(Digits::toLatin($search));

        return Customer::query()
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
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'code' => $customer->party->code,
                'name' => $customer->party->name,
                'phone' => $customer->party->phone,
                'credit_limit' => $customer->credit_limit,
                'payment_terms' => $customer->payment_terms,
            ])
            ->all();
    }

    /**
     * @return array<int, array{
     *     id: int, code: string, barcode: string|null, name: string, unit: string,
     *     sale_price: string, tax_rate: string, stock_quantity: string
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
                'unit' => $product->unit,
                'sale_price' => $product->sale_price,
                'tax_rate' => $product->tax_rate,
                'stock_quantity' => Decimal::normalize(
                    $product->getAttribute('stock_quantity') ?? 0,
                    Decimal::QUANTITY_SCALE,
                ),
            ])
            ->all();
    }

    private function likeTerm(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }
}
