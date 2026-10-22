<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property CheckType $type
 * @property CheckStatus $status
 */
class Check extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'check_number',
        'bank_name',
        'branch_name',
        'amount',
        'issue_date',
        'due_date',
        'type',
        'status',
        'customer_id',
        'supplier_id',
        'created_by',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'type' => CheckType::class,
            'status' => CheckStatus::class,
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

    protected static function booted(): void
    {
        static::saving(function (Check $check): void {
            if ($check->type === CheckType::Receivable) {
                if ($check->customer_id === null || $check->supplier_id !== null) {
                    throw new DomainException('چک دریافتی باید فقط به مشتری متصل باشد.');
                }

                return;
            }

            if ($check->supplier_id === null || $check->customer_id !== null) {
                throw new DomainException('چک پرداختی باید فقط به تأمین‌کننده متصل باشد.');
            }
        });
    }
}
