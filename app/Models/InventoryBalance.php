<?php

namespace App\Models;

use App\Support\Decimal;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $inventory_value
 */
class InventoryBalance extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'warehouse_id',
        'product_id',
        'quantity',
        'average_cost',
        'inventory_value',
        'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'average_cost' => 'decimal:4',
            'inventory_value' => 'decimal:2',
            'last_movement_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isAvailable(string|int|float $quantity): bool
    {
        return Decimal::compare($this->quantity, $quantity) >= 0;
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryBalance $balance): void {
            if (bccomp((string) $balance->quantity, '0', 3) < 0) {
                throw new DomainException('موجودی منفی مجاز نیست.');
            }

            if (bccomp((string) $balance->inventory_value, '0', 2) < 0) {
                throw new DomainException('ارزش موجودی نمی‌تواند منفی باشد.');
            }
        });
    }
}
