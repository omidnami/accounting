<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductManagementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Product
    {
        return DB::transaction(function () use ($data, $actor): Product {
            unset($data['version']);
            $parentId = isset($data['parent_product_id'])
                ? (int) $data['parent_product_id']
                : null;

            if ($parentId !== null) {
                $parent = Product::query()
                    ->whereKey($parentId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCanAddVariant($parent, $data);

                if (trim((string) ($data['name'] ?? '')) === trim($parent->name)) {
                    $variantLabel = collect([
                        $this->nullableString($data['variant_color'] ?? null),
                        $this->nullableString($data['variant_size'] ?? null),
                    ])->filter()->implode(' / ');
                    $data['name'] = trim($parent->name.' - '.$variantLabel);
                }
            }

            $product = Product::query()->create($data);
            $this->recordPriceHistory($product, $actor, 'create');

            return $product->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor): Product {
            $locked = Product::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->version !== (int) ($data['version'] ?? -1)) {
                throw new DomainException(
                    'این کالا هم‌زمان توسط کاربر دیگری تغییر کرده است. اطلاعات تازه را دوباره بررسی کنید.',
                );
            }

            unset($data['parent_product_id'], $data['version']);
            $previousPurchase = Decimal::normalize($locked->purchase_price, 2);
            $previousSale = Decimal::normalize($locked->sale_price, 2);

            if ($locked->track_batches !== (bool) ($data['track_batches'] ?? false)
                || $locked->track_serials !== (bool) ($data['track_serials'] ?? false)) {
                $hasStock = $locked->inventoryBalances()->where('quantity', '>', 0)->exists()
                    || $locked->inventoryMovements()->exists();
                $hasTracking = $locked->batches()->exists() || $locked->serials()->exists();

                if ((! $locked->track_batches && (bool) ($data['track_batches'] ?? false)
                    || ! $locked->track_serials && (bool) ($data['track_serials'] ?? false))
                    && $hasStock) {
                    throw new DomainException(
                        'فعال‌سازی ردیابی برای کالای دارای سابقهٔ موجودی مجاز نیست.',
                    );
                }

                if (($locked->track_batches && ! (bool) ($data['track_batches'] ?? false)
                    || $locked->track_serials && ! (bool) ($data['track_serials'] ?? false))
                    && $hasTracking) {
                    throw new DomainException(
                        'غیرفعال‌سازی ردیابی برای کالای دارای Batch یا سریال مجاز نیست.',
                    );
                }
            }

            $locked->update([
                ...$data,
                'version' => (int) $locked->version + 1,
            ]);

            if (Decimal::compare($previousPurchase, $locked->purchase_price, 2) !== 0
                || Decimal::compare($previousSale, $locked->sale_price, 2) !== 0) {
                $this->recordPriceHistory($locked, $actor, 'update');
            }

            return $locked->refresh();
        }, attempts: 3);
    }

    public function canAddVariant(Product $product): bool
    {
        return $product->parent_product_id === null
            && ! $product->invoiceItems()->exists()
            && ! $product->inventoryMovements()->exists()
            && ! $product->batches()->exists()
            && ! $product->serials()->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCanAddVariant(Product $parent, array $data): void
    {
        if (! $this->canAddVariant($parent)) {
            throw new DomainException(
                'برای این کالا به‌دلیل سابقهٔ موجودی یا فاکتور نمی‌توان تنوع جدید ساخت.',
            );
        }

        $color = $this->nullableString($data['variant_color'] ?? null);
        $size = $this->nullableString($data['variant_size'] ?? null);

        if ($color === null && $size === null) {
            throw new DomainException('برای تنوع کالا حداقل رنگ یا اندازه را وارد کنید.');
        }

        $duplicate = Product::query()
            ->where('parent_product_id', $parent->id)
            ->where('variant_color', $color)
            ->where('variant_size', $size)
            ->exists();

        if ($duplicate) {
            throw new DomainException('این ترکیب رنگ و اندازه قبلاً برای کالای والد ثبت شده است.');
        }
    }

    private function recordPriceHistory(
        Product $product,
        User $actor,
        string $source,
    ): void {
        ProductPriceHistory::query()->create([
            'product_id' => $product->id,
            'purchase_price' => $product->purchase_price,
            'sale_price' => $product->sale_price,
            'source' => $source,
            'changed_by' => $actor->id,
            'effective_at' => now(),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
