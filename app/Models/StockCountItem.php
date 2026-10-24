<?php

namespace App\Models;

use App\Enums\StockCountStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'stock_count_id',
        'product_id',
        'system_quantity',
        'counted_quantity',
        'difference_quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'difference_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::saving(function (StockCountItem $item): void {
            if ($item->stockCount()->where('status', '!=', StockCountStatus::Draft)->exists()) {
                throw new DomainException('ردیف شمارش ثبت‌شده قابل تغییر نیست.');
            }
        });

        static::deleting(function (StockCountItem $item): void {
            if ($item->stockCount()->where('status', '!=', StockCountStatus::Draft)->exists()) {
                throw new DomainException('ردیف شمارش ثبت‌شده قابل حذف نیست.');
            }
        });
    }
}
