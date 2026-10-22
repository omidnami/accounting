<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TreasuryDocumentStatus;
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
 */
class Receipt extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'receipt_number',
        'idempotency_key',
        'receipt_date',
        'amount',
        'payment_method',
        'treasury_account_id',
        'customer_id',
        'check_id',
        'created_by',
        'reference',
        'status',
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

            $original = $receipt->getRawOriginal('status');

            if ($receipt->exists && $original !== null) {
                $from = TreasuryDocumentStatus::from($original);
                $to = $receipt->status;
                $onlyStatus = array_diff(array_keys($receipt->getDirty()), ['status']) === [];
                $allowed = ($from === TreasuryDocumentStatus::Pending
                        && in_array($to, [
                            TreasuryDocumentStatus::Confirmed,
                            TreasuryDocumentStatus::Cancelled,
                        ], true))
                    || ($from === TreasuryDocumentStatus::Confirmed
                        && $to === TreasuryDocumentStatus::Bounced);

                if (! $onlyStatus || ! $allowed) {
                    throw new DomainException('دریافت تأییدشده قابل ویرایش نیست.');
                }
            }
        });

        static::deleting(function (Receipt $receipt): void {
            if ($receipt->status !== TreasuryDocumentStatus::Pending) {
                throw new DomainException('فقط دریافت در انتظار تأیید قابل حذف است.');
            }
        });
    }
}
