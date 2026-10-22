<?php

namespace App\Models;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $id
 * @property AccountType $type
 * @property AccountLevel $level
 * @property NormalBalance $normal_balance
 * @property bool $requires_party
 */
class Account extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'parent_id',
        'code',
        'name',
        'type',
        'level',
        'normal_balance',
        'requires_party',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'level' => AccountLevel::class,
            'normal_balance' => NormalBalance::class,
            'requires_party' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeGeneral(Builder $query): Builder
    {
        return $query->where('level', AccountLevel::General);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSubsidiary(Builder $query): Builder
    {
        return $query->where('level', AccountLevel::Subsidiary);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isGeneral(): bool
    {
        return $this->level === AccountLevel::General;
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account): void {
            if ($account->level === AccountLevel::General && $account->parent_id !== null) {
                throw new DomainException('حساب کل نمی‌تواند حساب والد داشته باشد.');
            }

            if ($account->level !== AccountLevel::Subsidiary) {
                return;
            }

            if ($account->parent_id === null) {
                throw new DomainException('حساب معین باید به یک حساب کل متصل باشد.');
            }

            if ($account->exists && $account->parent_id === $account->getKey()) {
                throw new DomainException('یک حساب نمی‌تواند والد خودش باشد.');
            }

            $parent = Account::query()->find($account->parent_id);

            if (! $parent || ! $parent->isGeneral()) {
                throw new DomainException('والد حساب معین باید یک حساب کل باشد.');
            }

            if ($parent->type !== $account->type) {
                throw new DomainException('نوع حساب معین باید با حساب کل والد یکسان باشد.');
            }

            if ((int) $parent->store_id !== (int) $account->store_id) {
                throw new DomainException('حساب کل و معین باید متعلق به یک فروشگاه باشند.');
            }

            $ancestor = $parent;
            $visited = [];

            while ($ancestor !== null) {
                if ($account->exists && $ancestor->getKey() === $account->getKey()) {
                    throw new DomainException('ساختار سلسله‌مراتبی حساب‌ها نمی‌تواند حلقه داشته باشد.');
                }

                if (isset($visited[$ancestor->id])) {
                    throw new DomainException('ساختار سلسله‌مراتبی حساب‌ها نامعتبر است.');
                }

                $visited[$ancestor->id] = true;
                $ancestor = $ancestor->parent;
            }
        });

        static::deleting(function (Account $account): void {
            if ($account->children()->exists()) {
                throw new DomainException('حسابی که زیرحساب دارد قابل حذف نیست.');
            }

            if ($account->journalEntryLines()->exists()) {
                throw new DomainException('حسابی که در اسناد حسابداری استفاده شده قابل حذف نیست.');
            }
        });
    }
}
