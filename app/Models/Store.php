<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'phone',
        'email',
        'national_id',
        'tax_id',
        'address',
        'currency',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function defaultBranch(): HasOne
    {
        return $this->hasOne(Branch::class)->where('is_default', true);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
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

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
