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
 * @property CarbonImmutable $receipt_date
 */
class Receipt extends Model
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
        'receipt_number',
        'idempotency_key',
        'idempotency_request_hash',
        'receipt_date',
        'amount',
        'payment_method',
        'treasury_account_id',
        'customer_id',
        'check_id',
        'created_by',
        'reference',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => TreasuryDocumentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Receipt $receipt): void {
            if (($receipt->payment_method === PaymentMethod::Check) !== ($receipt->check_id !== null)) {
                throw new DomainException('دریافت چکی باید به یک چک متصل باشد؛ سایر روش‌ها نباید چک داشته باشند.');
            }

            if ($receipt->exists
                && array_diff(array_keys($receipt->getDirty()), ['status']) !== []) {
                throw new DomainException('دریافت تأییدشده قابل ویرایش نیست.');
            }

            if ($receipt->exists && $receipt->isDirty('status') && ! $receipt->statusTransitionAuthorized) {
                throw new DomainException('تغییر وضعیت دریافت فقط از مسیر سرویس دامنه مجاز است.');
            }
        });

        static::deleting(function (Receipt $receipt): void {
            if ($receipt->status !== TreasuryDocumentStatus::Pending) {
                throw new DomainException('فقط دریافت در انتظار تأیید قابل حذف است.');
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
            throw new DomainException('دریافت بدون سند حسابداری ثبت‌شده قابل تأیید نیست.');
        }

        return $this->transitionTo(TreasuryDocumentStatus::Confirmed);
    }

    public function markBounced(): self
    {
        if (! $this->check?->bounce_entry_id) {
            throw new DomainException('دریافت بدون سند برگشت چک قابل Bounced شدن نیست.');
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
            throw new DomainException('گذار وضعیت دریافت مجاز نیست.');
        }

        $this->statusTransitionAuthorized = true;
        $this->status = $status;
        $this->save();

        return $this;
    }
}
