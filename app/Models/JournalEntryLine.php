<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $debit
 * @property numeric-string $credit
 */
class JournalEntryLine extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'party_id',
        'debit',
        'credit',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    protected static function booted(): void
    {
        static::saving(function (JournalEntryLine $line): void {
            $entry = $line->journalEntry()->first();

            if ($entry && $entry->status !== JournalEntryStatus::Draft) {
                throw new DomainException('ردیف سند ثبت‌شده قابل ویرایش نیست.');
            }

            $account = Account::query()->find($line->account_id);

            if (! $account || ! $account->is_active) {
                throw new DomainException('ردیف سند باید به یک حساب فعال متصل باشد.');
            }

            if ($account->isGeneral()) {
                throw new DomainException('ثبت ردیف سند فقط روی حساب معین مجاز است.');
            }

            if ($account->requires_party && $line->party_id === null) {
                throw new DomainException('این حساب نیازمند تعیین طرف‌حساب است.');
            }

            if ($line->party_id !== null) {
                $party = Party::query()->find($line->party_id);

                if (! $party || ! $party->is_active) {
                    throw new DomainException('طرف‌حساب ردیف سند باید فعال و معتبر باشد.');
                }
            }

            $debit = Decimal::normalize($line->debit, Decimal::MONEY_SCALE);
            $credit = Decimal::normalize($line->credit, Decimal::MONEY_SCALE);

            if (Decimal::compare($debit, '0', Decimal::MONEY_SCALE) < 0
                || Decimal::compare($credit, '0', Decimal::MONEY_SCALE) < 0) {
                throw new DomainException('مبلغ بدهکار یا بستانکار نمی‌تواند منفی باشد.');
            }

            if (Decimal::isPositive($debit) === Decimal::isPositive($credit)) {
                throw new DomainException('هر ردیف سند باید فقط یکی از مبالغ بدهکار یا بستانکار را داشته باشد.');
            }

            if ($entry && (int) $account->store_id !== (int) $entry->store_id) {
                throw new DomainException('حساب ردیف باید متعلق به فروشگاه سند باشد.');
            }
        });

        static::deleting(function (JournalEntryLine $line): void {
            if ($line->journalEntry()->where('status', JournalEntryStatus::Posted)->exists()) {
                throw new DomainException('ردیف سند ثبت‌شده قابل حذف نیست.');
            }
        });
    }
}
