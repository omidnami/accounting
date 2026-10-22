<?php

namespace App\Models;

use App\Enums\AccountingPeriodStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingPeriod extends Model
{
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

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
        });
    }
}
