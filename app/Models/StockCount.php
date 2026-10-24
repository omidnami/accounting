<?php

namespace App\Models;

use App\Enums\StockCountStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property StockCountStatus $status
 * @property CarbonImmutable $count_date
 * @property CarbonImmutable|null $posted_at
 */
class StockCount extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    private bool $statusTransitionAuthorized = false;

    protected $fillable = [
        'store_id',
        'branch_id',
        'warehouse_id',
        'fiscal_year_id',
        'accounting_period_id',
        'count_number',
        'count_date',
        'status',
        'notes',
        'idempotency_key',
        'idempotency_request_hash',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'immutable_date',
            'status' => StockCountStatus::class,
            'posted_at' => 'datetime',
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
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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

    /** @return HasMany<StockCountItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', StockCountStatus::Draft);
    }

    public function markPosted(User $user): self
    {
        $this->statusTransitionAuthorized = true;

        try {
            $this->forceFill([
                'status' => StockCountStatus::Posted,
                'posted_by' => $user->id,
                'posted_at' => now(),
            ])->save();
        } finally {
            $this->statusTransitionAuthorized = false;
        }

        return $this;
    }

    protected static function booted(): void
    {
        static::updating(function (StockCount $count): void {
            if (! $count->statusTransitionAuthorized
                && array_intersect(
                    ['status', 'posted_by', 'posted_at'],
                    array_keys($count->getDirty()),
                ) !== []) {
                throw new DomainException('وضعیت شمارش فقط از سرویس کنترل‌شده قابل تغییر است.');
            }
        });

        static::deleting(function (StockCount $count): void {
            if ($count->status !== StockCountStatus::Draft) {
                throw new DomainException('شمارش ثبت‌شده قابل حذف نیست.');
            }
        });
    }
}
