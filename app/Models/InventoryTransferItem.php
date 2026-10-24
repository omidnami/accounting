<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferItem extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    private bool $completionAuthorized = false;

    protected $fillable = [
        'inventory_transfer_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<InventoryTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recordCostSnapshot(string $unitCost, string $totalCost): self
    {
        $this->completionAuthorized = true;

        try {
            $this->forceFill([
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
            ])->save();
        } finally {
            $this->completionAuthorized = false;
        }

        return $this;
    }

    protected static function booted(): void
    {
        static::updating(function (InventoryTransferItem $item): void {
            if (! $item->completionAuthorized) {
                throw new DomainException('اقلام سند انتقال انبار قابل ویرایش نیستند.');
            }
        });

        static::deleting(function (): void {
            throw new DomainException('اقلام سند انتقال انبار قابل حذف نیستند.');
        });
    }
}
