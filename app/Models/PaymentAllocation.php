<?php

namespace App\Models;

use App\Enums\TreasuryDocumentStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount
 */
class PaymentAllocation extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function booted(): void
    {
        static::saving(function (PaymentAllocation $allocation): void {
            $payment = $allocation->payment()->first();

            if ($payment && $payment->status !== TreasuryDocumentStatus::Pending) {
                throw new DomainException('تخصیص پرداخت تأییدشده قابل ویرایش نیست.');
            }
        });

        static::deleting(function (PaymentAllocation $allocation): void {
            if ($allocation->payment()->where('status', '!=', TreasuryDocumentStatus::Pending)->exists()) {
                throw new DomainException('تخصیص پرداخت تأییدشده قابل حذف نیست.');
            }
        });
    }
}
