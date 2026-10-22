<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\JournalEntryStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property CheckType $type
 * @property CheckStatus $status
 */
class Check extends Model
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
        'check_number',
        'bank_name',
        'branch_name',
        'amount',
        'issue_date',
        'due_date',
        'type',
        'customer_id',
        'supplier_id',
        'created_by',
        'description',
        'settled_at',
        'bounced_at',
        'settlement_entry_id',
        'bounce_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'type' => CheckType::class,
            'status' => CheckStatus::class,
            'settled_at' => 'datetime',
            'bounced_at' => 'datetime',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<Receipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'settlement_entry_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function bounceEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'bounce_entry_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Check $check): void {
            if ($check->type === CheckType::Receivable) {
                if ($check->customer_id === null || $check->supplier_id !== null) {
                    throw new DomainException('چک دریافتی باید فقط به مشتری متصل باشد.');
                }

                return;
            }

            if ($check->supplier_id === null || $check->customer_id !== null) {
                throw new DomainException('چک پرداختی باید فقط به تأمین‌کننده متصل باشد.');
            }

            if ($check->exists
                && array_diff(array_keys($check->getDirty()), ['status']) !== []
                && ! $check->statusTransitionAuthorized
                && ($check->status !== CheckStatus::Pending
                    || $check->receipt()->exists()
                    || $check->payment()->exists())) {
                throw new DomainException('چک متصل یا تسویه‌شده قابل ویرایش نیست.');
            }

            if ($check->exists && $check->isDirty('status') && ! $check->statusTransitionAuthorized) {
                throw new DomainException('تغییر وضعیت چک فقط از مسیر سرویس دامنه مجاز است.');
            }
        });
    }

    public function markSettled(JournalEntry $entry): self
    {
        $this->assertSettlementEntry($entry);
        $status = $this->type === CheckType::Receivable
            ? CheckStatus::Collected
            : CheckStatus::Spent;

        $this->statusTransitionAuthorized = true;
        $this->status = $status;
        $this->settled_at = now();
        $this->settlement_entry_id = $entry->id;
        $this->save();

        return $this;
    }

    public function markBounced(JournalEntry $entry): self
    {
        $this->assertSettlementEntry($entry);
        $this->statusTransitionAuthorized = true;
        $this->status = CheckStatus::Bounced;
        $this->bounced_at = now();
        $this->bounce_entry_id = $entry->id;
        $this->save();

        return $this;
    }

    private function assertSettlementEntry(JournalEntry $entry): void
    {
        if ($this->status !== CheckStatus::Pending
            || $entry->status !== JournalEntryStatus::Posted
            || $entry->source_type !== $this->getMorphClass()
            || (int) $entry->source_id !== (int) $this->id) {
            throw new DomainException('سند تسویه چک معتبر نیست.');
        }
    }
}
