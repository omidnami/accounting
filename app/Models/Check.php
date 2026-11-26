<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\JournalEntryStatus;
use App\Services\Parties\PartySnapshotService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $amount
 * @property string|null $document_number
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable $due_date
 * @property CheckType $type
 * @property CheckStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $bounced_at
 * @property array<string, mixed>|null $party_snapshot
 * @property array<int, int>|null $source_invoice_ids
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
        'fiscal_year_id',
        'check_number',
        'document_number',
        'bank_name',
        'branch_name',
        'amount',
        'issue_date',
        'due_date',
        'type',
        'customer_id',
        'supplier_id',
        'created_by',
        'idempotency_key',
        'idempotency_request_hash',
        'source_invoice_ids',
        'party_snapshot',
        'description',
        'settled_at',
        'bounced_at',
        'settlement_entry_id',
        'settlement_treasury_account_id',
        'settlement_request_hash',
        'bounce_entry_id',
        'bounce_request_hash',
    ];

    protected function casts(): array
    {
        return [
            'source_invoice_ids' => 'array',
            'party_snapshot' => 'array',
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

    /** @return BelongsTo<TreasuryAccount, $this> */
    public function settlementTreasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'settlement_treasury_account_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function bounceEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'bounce_entry_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function partyIdentity(): array
    {
        $party = $this->type === CheckType::Receivable
            ? $this->customer?->party
            : $this->supplier?->party;

        return app(PartySnapshotService::class)->resolve($this->party_snapshot, $party);
    }

    protected static function booted(): void
    {
        static::saving(function (Check $check): void {
            if (in_array($check->status, [
                CheckStatus::Collected,
                CheckStatus::Spent,
                CheckStatus::Bounced,
            ], true) && $check->party_snapshot === null) {
                throw new DomainException('پیش از نهایی‌شدن چک، هویت طرف‌حساب باید snapshot شود.');
            }

            if ($check->exists && $check->isDirty(['type', 'store_id', 'branch_id', 'fiscal_year_id', 'document_number'])) {
                throw new DomainException('نوع، فروشگاه، شعبه، سال مالی و شماره سند چک قابل ویرایش نیست.');
            }

            if ($check->type === CheckType::Receivable) {
                if ($check->customer_id === null || $check->supplier_id !== null) {
                    throw new DomainException('چک دریافتی باید فقط به مشتری متصل باشد.');
                }
            } elseif ($check->supplier_id === null || $check->customer_id !== null) {
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

    public function markSettled(
        JournalEntry $entry,
        TreasuryAccount $treasuryAccount,
        ?string $requestHash = null,
    ): self {
        $this->assertPartySnapshot();
        $this->assertSettlementEntry($entry);

        if ((int) $treasuryAccount->store_id !== (int) $this->store_id
            || (int) $treasuryAccount->branch_id !== (int) $this->branch_id) {
            throw new DomainException('حساب تسویه چک باید متعلق به فروشگاه و شعبه چک باشد.');
        }

        $status = $this->type === CheckType::Receivable
            ? CheckStatus::Collected
            : CheckStatus::Spent;

        $this->statusTransitionAuthorized = true;

        try {
            $this->status = $status;
            $this->settled_at = now();
            $this->settlement_entry_id = $entry->id;
            $this->settlement_treasury_account_id = $treasuryAccount->id;
            $this->settlement_request_hash = $requestHash;
            $this->save();
        } finally {
            $this->statusTransitionAuthorized = false;
        }

        return $this;
    }

    public function markBounced(JournalEntry $entry, ?string $requestHash = null): self
    {
        $this->assertPartySnapshot();
        $this->assertSettlementEntry($entry);
        $this->statusTransitionAuthorized = true;

        try {
            $this->status = CheckStatus::Bounced;
            $this->bounced_at = now();
            $this->bounce_entry_id = $entry->id;
            $this->bounce_request_hash = $requestHash;
            $this->save();
        } finally {
            $this->statusTransitionAuthorized = false;
        }

        return $this;
    }

    public function markCancelled(string $reason): self
    {
        if ($this->status !== CheckStatus::Pending || $this->receipt()->exists() || $this->payment()->exists()) {
            throw new DomainException('فقط چک در انتظار و بدون اتصال قابل ابطال است.');
        }

        $this->statusTransitionAuthorized = true;

        try {
            $this->status = CheckStatus::Cancelled;
            $this->description = trim(($this->description ?? '')."\n".'ابطال: '.$reason);
            $this->save();
        } finally {
            $this->statusTransitionAuthorized = false;
        }

        return $this;
    }

    private function assertPartySnapshot(): void
    {
        if ($this->party_snapshot === null) {
            throw new DomainException('پیش از نهایی‌شدن چک، هویت طرف‌حساب باید snapshot شود.');
        }
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
