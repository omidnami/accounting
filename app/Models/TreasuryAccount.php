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
 * @property int $version
 * @property-read int $pending_receipts_count
 * @property-read int $pending_payments_count
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
        'default_slot',
        'is_active',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'type' => TreasuryAccountType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
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

    /** @return HasMany<Check, $this> */
    public function settledChecks(): HasMany
    {
        return $this->hasMany(Check::class, 'settlement_treasury_account_id');
    }

    protected static function booted(): void
    {
        static::saving(function (TreasuryAccount $treasury): void {
            if (! $treasury->is_active) {
                $treasury->is_default = false;
            }

            $treasury->default_slot = $treasury->is_default
                ? $treasury->type->value
                : null;
            $branch = Branch::query()->find($treasury->branch_id);
            $account = Account::query()->find($treasury->gl_account_id);

            if (! $branch
                || (int) $branch->store_id !== (int) $treasury->store_id
                || ($treasury->is_active && ! $branch->is_active)) {
                throw new DomainException('حساب خزانه و شعبه باید متعلق به یک فروشگاه باشند.');
            }

            $allowedCode = config(
                "accounting.treasury_account_gl_codes.{$treasury->type->value}",
            );

            if (! $account
                || $account->level !== AccountLevel::Subsidiary
                || $account->type !== AccountType::Asset
                || (int) $account->store_id !== (int) $treasury->store_id
                || ($treasury->is_active && ! $account->is_active)
                || (! is_string($allowedCode) || $account->code !== $allowedCode)) {
                throw new DomainException('حساب دفتر کل خزانه باید معین، دارایی، فعال و متعلق به همان فروشگاه باشد.');
            }

            if ($treasury->is_default) {
                self::query()
                    ->where('branch_id', $treasury->branch_id)
                    ->where('type', $treasury->type)
                    ->when(
                        $treasury->exists,
                        fn ($query) => $query->whereKeyNot($treasury->getKey()),
                    )
                    ->update([
                        'is_default' => false,
                        'default_slot' => null,
                    ]);
            }
        });
    }
}
