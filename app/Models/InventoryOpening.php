<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonImmutable $opening_date
 * @property numeric-string $total_cost
 * @property int|null $journal_entry_id
 * @property JournalEntry|null $journalEntry
 * @property int $items_count
 */
class InventoryOpening extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'warehouse_id',
        'fiscal_year_id',
        'opening_date',
        'status',
        'total_cost',
        'journal_entry_id',
        'idempotency_key',
        'request_hash',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'immutable_date',
            'total_cost' => 'decimal:2',
        ];
    }

    /** @return HasMany<InventoryOpeningItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryOpeningItem::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
