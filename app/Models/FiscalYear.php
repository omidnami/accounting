<?php

namespace App\Models;

use App\Enums\CalendarType;
use App\Enums\FiscalYearStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property FiscalYearStatus $status
 * @property CalendarType $calendar
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $closed_at
 */
class FiscalYear extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'title',
        'calendar',
        'starts_on',
        'ends_on',
        'status',
        'is_current',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => FiscalYearStatus::class,
            'calendar' => CalendarType::class,
            'is_current' => 'boolean',
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
     * @return HasMany<AccountingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    /**
     * @return HasMany<DocumentSequence, $this>
     */
    public function documentSequences(): HasMany
    {
        return $this->hasMany(DocumentSequence::class);
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    protected static function booted(): void
    {
        static::saving(function (FiscalYear $fiscalYear): void {
            if ($fiscalYear->starts_on->greaterThanOrEqualTo($fiscalYear->ends_on)) {
                throw new DomainException('تاریخ شروع سال مالی باید قبل از تاریخ پایان آن باشد.');
            }

            $overlap = self::query()
                ->where('store_id', $fiscalYear->store_id)
                ->when($fiscalYear->exists, fn ($query) => $query->whereKeyNot($fiscalYear->getKey()))
                ->where('starts_on', '<=', $fiscalYear->ends_on)
                ->where('ends_on', '>=', $fiscalYear->starts_on)
                ->exists();

            if ($overlap) {
                throw new DomainException('بازه سال مالی با سال مالی دیگری هم‌پوشانی دارد.');
            }
        });

        static::saved(function (FiscalYear $fiscalYear): void {
            if (! $fiscalYear->is_current) {
                return;
            }

            self::query()
                ->where('store_id', $fiscalYear->store_id)
                ->whereKeyNot($fiscalYear->getKey())
                ->where('is_current', true)
                ->update(['is_current' => false]);
        });
    }
}
