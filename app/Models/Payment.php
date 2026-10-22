<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryDocumentStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PaymentMethod $payment_method
 * @property TreasuryDocumentStatus $status
 * @property numeric-string $amount
 * @property CarbonImmutable $payment_date
 */
class Payment extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    private bool $statusTransitionAuthorized = false;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'payment_number',
        'idempotency_key',
        'idempotency_request_hash',
        'payment_date',
        'amount',
        'payment_method',
        'treasury_account_id',
        'supplier_id',
        'check_id',
        'created_by',
        'reference',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => TreasuryDocumentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * @return BelongsTo<Check, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @return BelongsTo<TreasuryAccount, $this> */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Payment $payment): void {
            if (($payment->payment_method === PaymentMethod::Check) !== ($payment->check_id !== null)) {
                throw new DomainException('پرداخت چکی باید به یک چک متصل باشد؛ سایر روش‌ها نباید چک داشته باشند.');
            }

            if ($payment->exists
                && array_diff(array_keys($payment->getDirty()), ['status']) !== []) {
                throw new DomainException('پرداخت تأییدشده قابل ویرایش نیست.');
            }

            if ($payment->exists && $payment->isDirty('status') && ! $payment->statusTransitionAuthorized) {
                throw new DomainException('تغییر وضعیت پرداخت فقط از مسیر سرویس دامنه مجاز است.');
            }
        });

        static::deleting(function (Payment $payment): void {
            if ($payment->status !== TreasuryDocumentStatus::Pending) {
                throw new DomainException('فقط پرداخت در انتظار تأیید قابل حذف است.');
            }
        });
    }

    public function markConfirmed(): self
    {
        $hasEntry = JournalEntry::query()
            ->where('source_type', $this->getMorphClass())
            ->where('source_id', $this->getKey())
            ->where('status', JournalEntryStatus::Posted)
            ->exists();

        if (! $hasEntry) {
            throw new DomainException('پرداخت بدون سند حسابداری ثبت‌شده قابل تأیید نیست.');
        }

        return $this->transitionTo(TreasuryDocumentStatus::Confirmed);
    }

    public function markBounced(): self
    {
        if (! $this->check?->bounce_entry_id) {
            throw new DomainException('پرداخت بدون سند برگشت چک قابل Bounced شدن نیست.');
        }

        return $this->transitionTo(TreasuryDocumentStatus::Bounced);
    }

    private function transitionTo(TreasuryDocumentStatus $status): self
    {
        $allowed = ($this->status === TreasuryDocumentStatus::Pending
                && in_array($status, [
                    TreasuryDocumentStatus::Confirmed,
                    TreasuryDocumentStatus::Cancelled,
                ], true))
            || ($this->status === TreasuryDocumentStatus::Confirmed
                && $status === TreasuryDocumentStatus::Bounced);

        if (! $allowed) {
            throw new DomainException('گذار وضعیت پرداخت مجاز نیست.');
        }

        $this->statusTransitionAuthorized = true;
        $this->status = $status;
        $this->save();

        return $this;
    }
}
