<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $transfer_date
 */
class InventoryTransfer extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    private bool $completionAuthorized = false;

    protected $fillable = [
        'store_id',
        'branch_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'product_id',
        'fiscal_year_id',
        'accounting_period_id',
        'transfer_number',
        'transfer_date',
        'quantity',
        'unit_cost',
        'total_cost',
        'reference',
        'notes',
        'idempotency_key',
        'idempotency_request_hash',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'immutable_date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<InventoryTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
        static::updating(function (InventoryTransfer $transfer): void {
            if (! $transfer->completionAuthorized) {
                throw new DomainException('سند انتقال انبار قابل ویرایش نیست.');
            }
        });

        static::deleting(function (): void {
            throw new DomainException('سند انتقال انبار قابل حذف نیست.');
        });
    }
}
