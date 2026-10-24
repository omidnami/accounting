<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $production_date
 * @property CarbonImmutable|null $expiry_date
 * @property int $serials_count
 */
class ProductBatch extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'batch_number',
        'production_date',
        'expiry_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductSerial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }
}
