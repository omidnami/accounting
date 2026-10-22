<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TreasuryDocumentStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PaymentMethod $payment_method
 */
class Payment extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'payment_number',
        'payment_date',
        'amount',
        'payment_method',
        'supplier_id',
        'check_id',
        'created_by',
        'reference',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => TreasuryDocumentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Check, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::saving(function (Payment $payment): void {
            if (($payment->payment_method === PaymentMethod::Check) !== ($payment->check_id !== null)) {
                throw new DomainException('پرداخت چکی باید به یک چک متصل باشد؛ سایر روش‌ها نباید چک داشته باشند.');
            }
        });
    }
}
