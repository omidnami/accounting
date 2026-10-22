<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'title',
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
            'is_current' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function documentSequences(): HasMany
    {
        return $this->hasMany(DocumentSequence::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

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
