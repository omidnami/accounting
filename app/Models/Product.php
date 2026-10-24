<?php

namespace App\Models;

use App\Enums\ProductStockPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $purchase_price
 * @property string $sale_price
 * @property string $tax_rate
 * @property string $reorder_level
 * @property string $safety_stock
 * @property string $reorder_quantity
 * @property string|null $max_stock
 * @property ProductStockPolicy $stock_policy
 * @property int|null $category_id
 * @property int|null $parent_product_id
 * @property string|null $variant_color
 * @property string|null $variant_size
 * @property bool $is_active
 * @property int $version
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
        'barcode',
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
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
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
}
