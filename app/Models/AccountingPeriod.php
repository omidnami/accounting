<?php

namespace App\Models;

use App\Enums\AccountingPeriodStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property AccountingPeriodStatus $status
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 */
class AccountingPeriod extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'fiscal_year_id',
        'name',
        'period_number',
        'starts_on',
        'ends_on',
        'status',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => AccountingPeriodStatus::class,
            'closed_at' => 'datetime',
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
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === AccountingPeriodStatus::Open;
    }

    protected static function booted(): void
    {
        static::saving(function (AccountingPeriod $period): void {
            if ($period->starts_on->greaterThanOrEqualTo($period->ends_on)) {
                throw new DomainException('تاریخ شروع دوره باید قبل از تاریخ پایان آن باشد.');
            }

            $fiscalYear = FiscalYear::query()->find($period->fiscal_year_id);

            if (! $fiscalYear) {
                throw new DomainException('سال مالی دوره معتبر نیست.');
            }

            if ((int) $fiscalYear->store_id !== (int) $period->store_id) {
                throw new DomainException('دوره و سال مالی باید متعلق به یک فروشگاه باشند.');
            }

            if ($period->starts_on->lessThan($fiscalYear->starts_on)
                || $period->ends_on->greaterThan($fiscalYear->ends_on)) {
                throw new DomainException('بازه دوره باید داخل بازه سال مالی باشد.');
            }

            $overlap = self::query()
                ->where('fiscal_year_id', $period->fiscal_year_id)
                ->when($period->exists, fn ($query) => $query->whereKeyNot($period->getKey()))
                ->where('starts_on', '<=', $period->ends_on)
                ->where('ends_on', '>=', $period->starts_on)
                ->exists();

            if ($overlap) {
                throw new DomainException('دوره مالی با یک دوره دیگر هم‌پوشانی دارد.');
            }
        });
    }
}
