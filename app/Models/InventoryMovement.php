<?php

namespace App\Models;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property InventoryDirection $direction
 * @property InventoryMovementType $movement_type
 * @property CarbonImmutable $movement_date
 */
class InventoryMovement extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'warehouse_id',
        'product_id',
        'direction',
        'movement_type',
        'quantity',
        'unit_cost',
        'total_cost',
        'quantity_after',
        'average_cost_after',
        'value_after',
        'movement_date',
        'source_type',
        'source_id',
        'idempotency_key',
        'created_by',
        'reference',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'direction' => InventoryDirection::class,
            'movement_type' => InventoryMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'quantity_after' => 'decimal:3',
            'average_cost_after' => 'decimal:4',
            'value_after' => 'decimal:2',
            'movement_date' => 'immutable_date',
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

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::creating(function (InventoryMovement $movement): void {
            if (! $movement->movement_type->supports($movement->direction)) {
                throw new DomainException('نوع حرکت با جهت ورود یا خروج انبار سازگار نیست.');
            }

            if (bccomp((string) $movement->quantity, '0', 3) <= 0) {
                throw new DomainException('مقدار حرکت انبار باید بزرگ‌تر از صفر باشد.');
            }

            if (bccomp((string) $movement->quantity_after, '0', 3) < 0) {
                throw new DomainException('موجودی پس از حرکت نمی‌تواند منفی باشد.');
            }
        });

        static::updating(function (): void {
            throw new DomainException('حرکت انبار قابل ویرایش نیست؛ برای اصلاح حرکت معکوس ثبت کنید.');
        });

        static::deleting(function (): void {
            throw new DomainException('حرکت انبار قابل حذف نیست؛ برای اصلاح حرکت معکوس ثبت کنید.');
        });
    }
}
