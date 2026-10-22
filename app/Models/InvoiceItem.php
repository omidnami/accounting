<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceItem extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'parent_invoice_item_id',
        'product_id',
        'product_code',
        'product_name',
        'unit',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_item_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function childItems(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_item_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
