<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refund_date' => 'immutable_date',
        ];
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
