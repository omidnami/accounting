<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JournalEntryStatus;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property InvoiceType $type
 * @property InvoiceStatus $status
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable|null $due_date
 */
class Invoice extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    private bool $statusTransitionAuthorized = false;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'invoice_number',
        'idempotency_key',
        'idempotency_request_hash',
        'type',
        'invoice_date',
        'due_date',
        'customer_id',
        'supplier_id',
        'parent_invoice_id',
        'subtotal',
        'discount_total',
        'tax_total',
        'additional_cost_total',
        'total',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'additional_cost_total' => 'decimal:2',
            'total' => 'decimal:2',
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
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function childInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id');
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<PurchaseAdditionalCost, $this>
     */
    public function additionalCosts(): HasMany
    {
        return $this->hasMany(PurchaseAdditionalCost::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'invoice_items')
            ->withPivot([
                'quantity',
                'unit_price',
                'discount_amount',
                'tax_rate',
                'tax_amount',
                'line_total',
            ])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function receiptAllocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice): void {
            if (in_array($invoice->type, [
                InvoiceType::Purchase,
                InvoiceType::PurchaseReturn,
            ], true)) {
                if ($invoice->supplier_id === null || $invoice->customer_id !== null) {
                    throw new DomainException('فاکتور خرید یا برگشت خرید باید فقط به تأمین‌کننده متصل باشد.');
                }
            } elseif ($invoice->customer_id === null || $invoice->supplier_id !== null) {
                throw new DomainException('فاکتور فروش یا پیش‌فاکتور باید فقط به مشتری متصل باشد.');
            }

            if (! $invoice->exists) {
                return;
            }

            $previous = $invoice->getRawOriginal('status');

            if ($previous === null) {
                return;
            }

            $previousStatus = InvoiceStatus::from($previous);
            $dirty = array_keys($invoice->getDirty());

            if ($invoice->isDirty('status') && ! $invoice->statusTransitionAuthorized) {
                throw new DomainException('تغییر وضعیت فاکتور فقط از مسیر سرویس دامنه مجاز است.');
            }

            if ($previousStatus === InvoiceStatus::Draft) {
                return;
            }

            if (array_diff($dirty, ['status']) !== []) {
                throw new DomainException('فاکتور پس از پیش‌نویس قابل ویرایش نیست.');
            }
        });

        static::deleting(function (Invoice $invoice): void {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط فاکتور پیش‌نویس قابل حذف است.');
            }
        });
    }

    public function confirmDocument(): self
    {
        if (! $this->exists || $this->status !== InvoiceStatus::Draft) {
            throw new DomainException('فقط فاکتور پیش‌نویس قابل تأیید است.');
        }

        $hasPostedEntry = JournalEntry::query()
            ->where('source_type', $this->getMorphClass())
            ->where('source_id', $this->getKey())
            ->where('status', JournalEntryStatus::Posted)
            ->exists();

        if (! $hasPostedEntry) {
            throw new DomainException('فاکتور بدون سند حسابداری ثبت‌شده قابل تأیید نیست.');
        }

        $this->transitionStatusTo(InvoiceStatus::Confirmed);

        return $this;
    }

    public function syncSettlementStatus(): self
    {
        $settled = '0.00';
        $settlementRows = $this->type === InvoiceType::Sales
            ? ReceiptAllocation::query()
                ->join('receipts', 'receipts.id', '=', 'receipt_allocations.receipt_id')
                ->where('receipt_allocations.invoice_id', $this->id)
                ->where('receipts.status', 'confirmed')
            : PaymentAllocation::query()
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->where('payment_allocations.invoice_id', $this->id)
                ->where('payments.status', 'confirmed');

        foreach ($settlementRows
            ->select([
                $this->type === InvoiceType::Sales
                    ? 'receipt_allocations.amount as amount'
                    : 'payment_allocations.amount as amount',
            ])
            ->orderBy(
                $this->type === InvoiceType::Sales
                    ? 'receipt_allocations.id'
                    : 'payment_allocations.id',
            )
            ->lockForUpdate()
            ->get() as $allocation) {
            $settled = Decimal::add($settled, $allocation->amount, Decimal::MONEY_SCALE);
        }

        $returned = '0.00';
        $returnRows = self::query()
            ->where('parent_invoice_id', $this->id)
            ->where('type', $this->type === InvoiceType::Sales
                ? InvoiceType::SalesReturn
                : InvoiceType::PurchaseReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['total']);

        foreach ($returnRows as $return) {
            $returned = Decimal::add($returned, $return->total, Decimal::MONEY_SCALE);
        }

        $remaining = Decimal::subtract($this->total, $settled, 2);
        $remaining = Decimal::subtract($remaining, $returned, 2);

        $status = Decimal::compare($returned, $this->total, 2) >= 0
            ? InvoiceStatus::Reversed
            : (Decimal::compare($remaining, '0', 2) <= 0
                ? InvoiceStatus::Paid
                : (Decimal::isPositive($settled)
                    ? InvoiceStatus::PartiallyPaid
                    : InvoiceStatus::Confirmed));
        $this->transitionStatusTo($status);

        return $this;
    }

    private function transitionStatusTo(InvoiceStatus $status): void
    {
        if ($status === $this->status) {
            return;
        }

        if (! $this->isAllowedStatusTransition($this->status, $status)) {
            throw new DomainException('گذار وضعیت فاکتور مجاز نیست.');
        }

        $this->statusTransitionAuthorized = true;
        $this->status = $status;
        $this->save();
    }

    private function isAllowedStatusTransition(
        InvoiceStatus $from,
        InvoiceStatus $to,
    ): bool {
        return in_array([$from, $to], [
            [InvoiceStatus::Draft, InvoiceStatus::Confirmed],
            [InvoiceStatus::Draft, InvoiceStatus::Cancelled],
            [InvoiceStatus::Confirmed, InvoiceStatus::PartiallyPaid],
            [InvoiceStatus::Confirmed, InvoiceStatus::Paid],
            [InvoiceStatus::Confirmed, InvoiceStatus::Reversed],
            [InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid],
            [InvoiceStatus::PartiallyPaid, InvoiceStatus::Confirmed],
            [InvoiceStatus::PartiallyPaid, InvoiceStatus::Reversed],
            [InvoiceStatus::Paid, InvoiceStatus::Reversed],
            [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid],
            [InvoiceStatus::Paid, InvoiceStatus::Confirmed],
        ], true);
    }
}
