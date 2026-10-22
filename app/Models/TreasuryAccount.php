<?php

namespace App\Models;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\TreasuryAccountType;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TreasuryAccountType $type
 */
class TreasuryAccount extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'gl_account_id',
        'code',
        'name',
        'type',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => TreasuryAccountType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
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

    /** @return BelongsTo<Account, $this> */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /** @return HasMany<Receipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected static function booted(): void
    {
        static::saving(function (TreasuryAccount $treasury): void {
            $branch = Branch::query()->find($treasury->branch_id);
            $account = Account::query()->find($treasury->gl_account_id);

            if (! $branch || (int) $branch->store_id !== (int) $treasury->store_id) {
                throw new DomainException('حساب خزانه و شعبه باید متعلق به یک فروشگاه باشند.');
            }

            if (! $account
                || $account->level !== AccountLevel::Subsidiary
                || $account->type !== AccountType::Asset
                || ! $account->is_active
                || (int) $account->store_id !== (int) $treasury->store_id) {
                throw new DomainException('حساب دفتر کل خزانه باید معین، دارایی، فعال و متعلق به همان فروشگاه باشد.');
            }
        });

        static::saved(function (TreasuryAccount $treasury): void {
            if (! $treasury->is_default) {
                return;
            }

            self::query()
                ->where('branch_id', $treasury->branch_id)
                ->where('type', $treasury->type)
                ->whereKeyNot($treasury->getKey())
                ->update(['is_default' => false]);
        });
    }
}
