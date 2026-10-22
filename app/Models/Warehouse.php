<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'code',
        'name',
        'location',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
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

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (Warehouse $warehouse): void {
            $branch = Branch::query()->find($warehouse->branch_id);

            if (! $branch) {
                throw new DomainException('شعبه انبار معتبر نیست.');
            }

            if ((int) $branch->store_id !== (int) $warehouse->store_id) {
                throw new DomainException('انبار و شعبه باید متعلق به یک فروشگاه باشند.');
            }
        });
    }
}
