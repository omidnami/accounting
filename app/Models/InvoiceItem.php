<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $line_total
 * @property string|null $total_cost_snapshot
 * @property array<int, string>|null $purchase_serial_numbers
 * @property CarbonImmutable|null $purchase_expiry_date
 */
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
        'unit_cost_snapshot',
        'total_cost_snapshot',
        'sort_order',
        'purchase_batch_number',
        'purchase_expiry_date',
        'purchase_serial_numbers',
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
            'unit_cost_snapshot' => 'decimal:4',
            'total_cost_snapshot' => 'decimal:2',
            'purchase_expiry_date' => 'immutable_date',
            'purchase_serial_numbers' => 'array',
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

    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item): void {
            $invoice = $item->invoice()->first();

            if ($invoice && $invoice->status !== InvoiceStatus::Draft) {
                throw new DomainException('اقلام فاکتور پس از پیش‌نویس قابل ویرایش نیستند.');
            }
        });

        static::deleting(function (InvoiceItem $item): void {
            if ($item->invoice()->where('status', '!=', InvoiceStatus::Draft)->exists()) {
                throw new DomainException('اقلام فاکتور پس از پیش‌نویس قابل حذف نیستند.');
            }
        });
    }
}
