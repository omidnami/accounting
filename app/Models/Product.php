<?php

namespace App\Models;

use App\Enums\ProductStockPolicy;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $purchase_price
 * @property string $sale_price
 * @property string $tax_rate
 * @property string $item_type
 * @property string $reorder_level
 * @property string $safety_stock
 * @property string $reorder_quantity
 * @property string|null $max_stock
 * @property ProductStockPolicy $stock_policy
 * @property bool $track_batches
 * @property bool $track_serials
 * @property int|null $category_id
 * @property int|null $parent_product_id
 * @property string|null $variant_color
 * @property string|null $variant_size
 * @property bool $is_active
 * @property int $version
 * @property string|null $image_path
 */
class Product extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'code',
        'category_id',
        'parent_product_id',
        'name',
        'item_type',
        'barcode',
        'image_path',
        'variant_color',
        'variant_size',
        'unit',
        'purchase_price',
        'sale_price',
        'tax_rate',
        'reorder_level',
        'safety_stock',
        'reorder_quantity',
        'max_stock',
        'stock_policy',
        'track_batches',
        'track_serials',
        'description',
        'is_active',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'safety_stock' => 'decimal:3',
            'reorder_quantity' => 'decimal:3',
            'max_stock' => 'decimal:3',
            'stock_policy' => ProductStockPolicy::class,
            'track_batches' => 'boolean',
            'track_serials' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function isStockItem(): bool
    {
        return $this->item_type === 'stock';
    }

    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : route('products.image', [
            'product' => $this->id,
            'v' => sha1($this->image_path),
        ]);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    /** @return HasMany<Product, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_product_id');
    }

    /** @return HasMany<ProductPriceHistory, $this> */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->latest('effective_at');
    }

    /** @return HasMany<ProductBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /** @return HasMany<ProductSerial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * @return HasMany<InventoryBalance, $this>
     */
    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            foreach (['code', 'barcode'] as $identifier) {
                $value = $product->getAttribute($identifier);

                if (! is_string($value) || ($product->exists && ! $product->isDirty($identifier))) {
                    continue;
                }

                $value = trim(Digits::toLatin($value));
                $product->setAttribute(
                    $identifier,
                    $identifier === 'barcode' && $value === '' ? null : $value,
                );
            }

            $itemType = $product->item_type ?? 'stock';
            $product->item_type = $itemType;

            if (! in_array($itemType, ['stock', 'non_stock', 'service'], true)) {
                throw new DomainException('نوع کالا یا خدمت معتبر نیست.');
            }

            if ($itemType !== 'stock'
                && ($product->track_batches || $product->track_serials || $product->parent_product_id !== null)) {
                throw new DomainException('کالای غیرانباری و خدمت نمی‌توانند سری ساخت، سریال یا تنوع داشته باشند.');
            }

            if ($product->exists
                && $product->isDirty('item_type')
                && $product->inventoryMovements()->exists()) {
                throw new DomainException('نوع کالای دارای گردش انبار قابل تغییر نیست.');
            }
        });
    }
}
