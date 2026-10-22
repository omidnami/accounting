<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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

            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);

            if ($debit < 0 || $credit < 0) {
                throw new DomainException('مبلغ بدهکار یا بستانکار نمی‌تواند منفی باشد.');
            }

            if (($debit > 0) === ($credit > 0)) {
                throw new DomainException('هر ردیف سند باید فقط یکی از مبالغ بدهکار یا بستانکار را داشته باشد.');
            }
        });

        static::deleting(function (JournalEntryLine $line): void {
            if ($line->journalEntry()->where('status', JournalEntryStatus::Posted)->exists()) {
                throw new DomainException('ردیف سند ثبت‌شده قابل حذف نیست.');
            }
        });
    }
}
