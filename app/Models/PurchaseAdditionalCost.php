<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseAdditionalCost extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'description',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function booted(): void
    {
        static::saving(function (PurchaseAdditionalCost $cost): void {
            $invoice = $cost->invoice()->first();

            if ($invoice && $invoice->status !== InvoiceStatus::Draft) {
                throw new DomainException('هزینه‌های جانبی فاکتور پس از پیش‌نویس قابل ویرایش نیستند.');
            }
        });

        static::deleting(function (PurchaseAdditionalCost $cost): void {
            if ($cost->invoice()->where('status', '!=', InvoiceStatus::Draft)->exists()) {
                throw new DomainException('هزینه‌های جانبی فاکتور پس از پیش‌نویس قابل حذف نیستند.');
            }
        });
    }
}
