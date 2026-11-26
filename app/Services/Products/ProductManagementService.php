<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Digits;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductManagementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Product
    {
        $data = $this->normalizeIdentifiers($data);

        return $this->withImage($data, function (array $data) use ($actor): Product {
            return $this->productTransaction(function () use ($data, $actor): Product {
                unset($data['version']);
                $this->assertIdentifiersAvailable(null, $data['code'] ?? null, $data['barcode'] ?? null);
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
            });
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, User $actor): Product
    {
        $data = $this->normalizeIdentifiers($data);

        return $this->withImage($data, function (array $data) use ($product, $actor): Product {
            return $this->productTransaction(function () use ($product, $data, $actor): Product {
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
                $this->assertIdentifiersAvailable(
                    $locked->id,
                    $this->normalizeIdentifier($data['code'] ?? $locked->code),
                    array_key_exists('barcode', $data)
                        ? $this->normalizeIdentifier($data['barcode'])
                        : $this->normalizeIdentifier($locked->barcode),
                );
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
                            'غیرفعال‌سازی ردیابی برای کالای دارای سری ساخت یا سریال مجاز نیست.',
                        );
                    }
                }

                $previousImage = $locked->image_path;
                $locked->update([
                    ...$data,
                    'version' => (int) $locked->version + 1,
                ]);

                if (Decimal::compare($previousPurchase, $locked->purchase_price, 2) !== 0
                    || Decimal::compare($previousSale, $locked->sale_price, 2) !== 0) {
                    $this->recordPriceHistory($locked, $actor, 'update');
                }

                if ($previousImage !== null && $previousImage !== $locked->image_path) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($previousImage));
                }

                return $locked->refresh();
            });
        });
    }

    /**
     * @param  Closure(): Product  $save
     */
    private function productTransaction(Closure $save): Product
    {
        try {
            return DB::transaction($save, attempts: 3);
        } catch (QueryException $exception) {
            $this->throwIdentifierConflict($exception);
        }
    }

    /**
     * Lock conflicting identifier rows (and their indexed gaps on MySQL/MariaDB)
     * inside the same transaction that writes the product.
     */
    private function assertIdentifiersAvailable(?int $productId, ?string $code, ?string $barcode): void
    {
        if ($code === null || $code === '') {
            throw new DomainException('کد کالا الزامی است.');
        }

        $identifiers = array_values(array_unique(array_filter(
            [$code, $barcode],
            fn (?string $identifier): bool => $identifier !== null && $identifier !== '',
        )));
        $conflict = Product::query()
            ->when($productId !== null, fn (Builder $query) => $query->where('products.id', '!=', $productId))
            ->where(function (Builder $query) use ($identifiers): void {
                $query->whereIn('products.code', $identifiers)
                    ->orWhereIn('products.barcode', $identifiers);
            })
            ->lockForUpdate()
            ->first(['id', 'code', 'barcode']);

        if ($conflict === null) {
            return;
        }

        if ($conflict->code === $code || $conflict->barcode === $code) {
            throw new DomainException("کد کالا «{$code}» قبلاً برای کالای دیگری ثبت شده است.");
        }

        if ($barcode !== null && ($conflict->code === $barcode || $conflict->barcode === $barcode)) {
            throw new DomainException("بارکد «{$barcode}» قبلاً برای کالای دیگری ثبت شده است.");
        }

        throw new DomainException('کد کالا یا بارکد با شناسهٔ کالای دیگری تداخل دارد.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeIdentifiers(array $data): array
    {
        foreach (['code', 'barcode'] as $identifier) {
            if (! array_key_exists($identifier, $data) || ! is_string($data[$identifier])) {
                continue;
            }

            $data[$identifier] = $this->normalizeIdentifier($data[$identifier]);
        }

        return $data;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(Digits::toLatin($value));

        return $value === '' ? null : $value;
    }

    private function throwIdentifierConflict(QueryException $exception): never
    {
        if (str_contains($exception->getMessage(), 'products_identifier_unique')) {
            throw new DomainException('کد کالا یا بارکد با شناسهٔ کالای دیگری تداخل دارد.');
        }

        throw $exception;
    }

    /**
     * Store once outside the retried database transaction; remove failed uploads and
     * leave the previous image untouched until the product change commits.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): Product  $save
     */
    private function withImage(array $data, callable $save): Product
    {
        $image = $data['image'] ?? null;
        $remove = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);
        $path = null;

        if ($image instanceof UploadedFile) {
            $path = $image->store('products', 'local');

            if ($path === false) {
                throw new RuntimeException('ذخیره تصویر کالا انجام نشد.');
            }

            $data['image_path'] = $path;
        } elseif ($remove) {
            $data['image_path'] = null;
        }

        try {
            return $save($data);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
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
