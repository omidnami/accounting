<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryOpeningItem extends Model
{
    protected $fillable = [
        'inventory_opening_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'product_batch_id',
        'serial_numbers',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'serial_numbers' => 'array',
        ];
    }

    /** @return BelongsTo<InventoryOpening, $this> */
    public function opening(): BelongsTo
    {
        return $this->belongsTo(InventoryOpening::class, 'inventory_opening_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
