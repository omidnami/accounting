<?php

namespace App\Models;

use App\Services\Parties\PartySnapshotService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $party_snapshot
 */
class ReturnRefund extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'source_invoice_id',
        'original_invoice_id',
        'party_id',
        'direction',
        'amount',
        'refund_date',
        'treasury_account_id',
        'status',
        'journal_entry_id',
        'idempotency_key',
        'request_hash',
        'party_snapshot',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'party_snapshot' => 'array',
            'amount' => 'decimal:2',
            'refund_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function partyIdentity(): array
    {
        return app(PartySnapshotService::class)->resolve($this->party_snapshot, $this->party);
    }

    protected static function booted(): void
    {
        static::saving(function (ReturnRefund $refund): void {
            if ($refund->status === 'posted' && $refund->party_snapshot === null) {
                throw new DomainException('پیش از ثبت نهایی refund، هویت طرف‌حساب باید snapshot شود.');
            }
        });
    }

    /** @return BelongsTo<Invoice, $this> */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<TreasuryAccount, $this> */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }
}
