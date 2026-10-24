<?php

namespace App\Models;

use App\Enums\ProductSerialStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ProductSerialStatus $status
 * @property int|null $product_batch_id
 * @property int|null $warehouse_id
 * @property string $serial_number
 * @property CarbonImmutable|null $acquired_at
 * @property CarbonImmutable|null $sold_at
 */
class ProductSerial extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_batch_id',
        'warehouse_id',
        'serial_number',
        'status',
        'acquired_at',
        'sold_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductSerialStatus::class,
            'acquired_at' => 'immutable_date',
            'sold_at' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
