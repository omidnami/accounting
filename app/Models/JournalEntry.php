<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'entry_number',
        'entry_date',
        'fiscal_year_id',
        'accounting_period_id',
        'description',
        'status',
        'source_type',
        'source_id',
        'reversal_of_id',
        'created_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'status' => JournalEntryStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', JournalEntryStatus::Posted);
    }

    /**
     * Persist an entry and its lines atomically, rejecting an unbalanced entry.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function createBalanced(array $attributes, array $lines): self
    {
        return DB::transaction(function () use ($attributes, $lines): self {
            $requestedStatus = $attributes['status'] ?? JournalEntryStatus::Draft;
            unset($attributes['status'], $attributes['posted_at']);

            $entry = self::query()->create([
                ...$attributes,
                'status' => JournalEntryStatus::Draft,
            ]);

            foreach (array_values($lines) as $index => $line) {
                $entry->lines()->create([
                    ...$line,
                    'sort_order' => $line['sort_order'] ?? $index + 1,
                ]);
            }

            $entry->load('lines');
            $entry->assertBalanced();

            if ($requestedStatus === JournalEntryStatus::Posted || $requestedStatus === JournalEntryStatus::Posted->value) {
                $entry->post();
            }

            return $entry->refresh()->load('lines');
        });
    }

    public function post(): self
    {
        if ($this->status !== JournalEntryStatus::Draft) {
            throw new DomainException('فقط سند پیش‌نویس قابل ثبت است.');
        }

        $this->assertBalanced();
        $this->status = JournalEntryStatus::Posted;
        $this->posted_at = now();
        $this->save();

        return $this;
    }

    public function totalDebit(): float
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return round($lines->sum(fn (JournalEntryLine $line): float => (float) $line->debit), 2);
    }

    public function totalCredit(): float
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return round($lines->sum(fn (JournalEntryLine $line): float => (float) $line->credit), 2);
    }

    public function isBalanced(): bool
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->count() >= 2
            && $this->totalDebit() > 0
            && abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }

    public function assertBalanced(): void
    {
        if (! $this->isBalanced()) {
            throw new DomainException('جمع بدهکار و بستانکار سند باید حداقل دو ردیف و کاملاً برابر باشد.');
        }
    }

    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry): void {
            if ($entry->status === JournalEntryStatus::Posted) {
                throw new DomainException('سند باید ابتدا به‌صورت پیش‌نویس ساخته و پس از کنترل توازن ثبت شود.');
            }
        });

        static::updating(function (JournalEntry $entry): void {
            if ($entry->isDirty('status') && $entry->status === JournalEntryStatus::Posted) {
                $entry->assertBalanced();
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if ($entry->status !== JournalEntryStatus::Draft) {
                throw new DomainException('سند ثبت‌شده قابل حذف نیست؛ برای اصلاح از سند برگشتی استفاده کنید.');
            }
        });
    }
}
