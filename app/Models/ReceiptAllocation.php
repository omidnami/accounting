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
class ReceiptAllocation extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = ['receipt_id', 'invoice_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
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
        static::saving(function (ReceiptAllocation $allocation): void {
            $receipt = $allocation->receipt()->first();

            if ($receipt && $receipt->status !== TreasuryDocumentStatus::Pending) {
                throw new DomainException('تخصیص دریافت تأییدشده قابل ویرایش نیست.');
            }
        });

        static::deleting(function (ReceiptAllocation $allocation): void {
            if ($allocation->receipt()->where('status', '!=', TreasuryDocumentStatus::Pending)->exists()) {
                throw new DomainException('تخصیص دریافت تأییدشده قابل حذف نیست.');
            }
        });
    }
}
