<?php

namespace App\Models;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property JournalEntryStatus $status
 * @property JournalEntryKind $entry_kind
 * @property CarbonImmutable $entry_date
 */
class JournalEntry extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'entry_number',
        'entry_date',
        'fiscal_year_id',
        'accounting_period_id',
        'description',
        'entry_kind',
        'source_type',
        'source_id',
        'reversal_of_id',
        'created_by',
    ];

    private bool $postingTransitionAuthorized = false;

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'status' => JournalEntryStatus::class,
            'entry_kind' => JournalEntryKind::class,
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', JournalEntryStatus::Posted);
    }

    /**
     * @param  Collection<int, JournalEntryLine>|null  $lines
     */
    public function totalDebit(?Collection $lines = null): string
    {
        $lines ??= $this->lines()->get();
        $total = '0.00';

        foreach ($lines as $line) {
            $total = Decimal::add($total, $line->debit, Decimal::MONEY_SCALE);
        }

        return $total;
    }

    /**
     * @param  Collection<int, JournalEntryLine>|null  $lines
     */
    public function totalCredit(?Collection $lines = null): string
    {
        $lines ??= $this->lines()->get();
        $total = '0.00';

        foreach ($lines as $line) {
            $total = Decimal::add($total, $line->credit, Decimal::MONEY_SCALE);
        }

        return $total;
    }

    /**
     * @param  Collection<int, JournalEntryLine>|null  $lines
     */
    public function isBalanced(?Collection $lines = null): bool
    {
        $lines ??= $this->lines()->get();
        $debit = $this->totalDebit($lines);
        $credit = $this->totalCredit($lines);

        return $lines->count() >= 2
            && Decimal::compare($debit, '0', Decimal::MONEY_SCALE) > 0
            && Decimal::compare($debit, $credit, Decimal::MONEY_SCALE) === 0;
    }

    /**
     * @param  Collection<int, JournalEntryLine>|null  $lines
     */
    public function assertBalanced(?Collection $lines = null): void
    {
        if (! $this->isBalanced($lines)) {
            throw new DomainException('جمع بدهکار و بستانکار سند باید حداقل دو ردیف و کاملاً برابر باشد.');
        }
    }

    public function finalizePosting(User $approver): self
    {
        if (! $approver->canApproveJournalEntries()) {
            throw new AuthorizationException('تأیید و ثبت سند فقط برای مدیر و حسابدار مجاز است.');
        }

        return DB::transaction(function (): self {
            $entry = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($entry->status !== JournalEntryStatus::Draft) {
                throw new DomainException('فقط سند پیش‌نویس قابل ثبت است.');
            }

            $lines = $entry->lines()->lockForUpdate()->get();
            $entry->validatePostingScope($lines);
            $entry->assertBalanced($lines);
            $entry->postingTransitionAuthorized = true;
            $entry->status = JournalEntryStatus::Posted;
            $entry->posted_at = now();
            $entry->save();

            return $entry->refresh()->load(['lines', 'creator']);
        });
    }

    /**
     * @param  Collection<int, JournalEntryLine>  $lines
     */
    private function validatePostingScope(Collection $lines): void
    {
        if ($this->store_id === null
            || $this->branch_id === null
            || $this->fiscal_year_id === null
            || $this->accounting_period_id === null) {
            throw new DomainException('فروشگاه، شعبه، سال مالی و دوره برای ثبت سند الزامی هستند.');
        }

        $branch = Branch::query()->find($this->branch_id);
        $fiscalYear = FiscalYear::query()->find($this->fiscal_year_id);
        $period = AccountingPeriod::query()->find($this->accounting_period_id);

        if (! $branch || (int) $branch->store_id !== (int) $this->store_id) {
            throw new DomainException('شعبه سند باید متعلق به فروشگاه سند باشد.');
        }

        if (! $fiscalYear || (int) $fiscalYear->store_id !== (int) $this->store_id) {
            throw new DomainException('سال مالی سند باید متعلق به فروشگاه سند باشد.');
        }

        if (! $period
            || (int) $period->store_id !== (int) $this->store_id
            || (int) $period->fiscal_year_id !== (int) $this->fiscal_year_id) {
            throw new DomainException('دوره سند باید متعلق به فروشگاه و سال مالی سند باشد.');
        }

        if ($this->entry_date->lessThan($period->starts_on)
            || $this->entry_date->greaterThan($period->ends_on)
            || $this->entry_date->lessThan($fiscalYear->starts_on)
            || $this->entry_date->greaterThan($fiscalYear->ends_on)) {
            throw new DomainException('تاریخ سند باید داخل دوره و سال مالی باشد.');
        }

        if ($period->status !== AccountingPeriodStatus::Open) {
            throw new DomainException('ثبت سند در دوره بسته یا نیمه‌بسته مجاز نیست.');
        }

        if ($fiscalYear->status !== FiscalYearStatus::Open) {
            throw new DomainException('ثبت سند در سال مالی غیرفعال مجاز نیست.');
        }

        $accounts = Account::query()
            ->whereIn('id', $lines->pluck('account_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== $lines->pluck('account_id')->unique()->count()) {
            throw new DomainException('همه حساب‌های ردیف سند باید وجود داشته باشند.');
        }

        foreach ($accounts as $account) {
            if (! $account->is_active || $account->isGeneral()) {
                throw new DomainException('تمام حساب‌های سند باید معین و فعال باشند.');
            }

            if ((int) $account->store_id !== (int) $this->store_id) {
                throw new DomainException('تمام حساب‌های سند باید متعلق به فروشگاه سند باشند.');
            }

            $line = $lines->firstWhere('account_id', $account->id);

            if ($account->requires_party && $line?->party_id === null) {
                throw new DomainException('ردیف‌های حساب دریافتنی یا پرداختنی باید طرف‌حساب داشته باشند.');
            }
        }

        $partyIds = $lines->pluck('party_id')->filter()->unique();

        if ($partyIds->isEmpty()) {
            return;
        }

        $activeParties = Party::query()
            ->whereIn('id', $partyIds)
            ->where('store_id', $this->store_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->pluck('id');

        if ($activeParties->count() !== $partyIds->count()) {
            throw new DomainException('همه طرف‌حساب‌های ردیف سند باید وجود داشته و فعال باشند.');
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
            $originalStatus = $entry->getRawOriginal('status');

            if ($entry->isDirty('entry_kind')) {
                throw new DomainException('نوع سند پس از ایجاد قابل تغییر نیست.');
            }

            if ($originalStatus !== null
                && JournalEntryStatus::from($originalStatus) !== JournalEntryStatus::Draft) {
                throw new DomainException('سند پس از ثبت قابل ویرایش نیست؛ برای اصلاح سند برگشتی ثبت کنید.');
            }

            if ($entry->isDirty('status')
                && (! $entry->postingTransitionAuthorized
                    || $entry->status !== JournalEntryStatus::Posted)) {
                throw new DomainException('تغییر وضعیت سند فقط از مسیر تأیید PostingService مجاز است.');
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if ($entry->status !== JournalEntryStatus::Draft) {
                throw new DomainException('سند ثبت‌شده قابل حذف نیست؛ برای اصلاح از سند برگشتی استفاده کنید.');
            }
        });
    }
}
