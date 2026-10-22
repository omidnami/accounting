<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'invoice_number',
        'type',
        'invoice_date',
        'due_date',
        'customer_id',
        'supplier_id',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'status',
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
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice): void {
            if ($invoice->type === InvoiceType::Purchase) {
                if ($invoice->supplier_id === null || $invoice->customer_id !== null) {
                    throw new DomainException('فاکتور خرید باید فقط به تأمین‌کننده متصل باشد.');
                }

                return;
            }

            if ($invoice->customer_id === null || $invoice->supplier_id !== null) {
                throw new DomainException('فاکتور فروش یا پیش‌فاکتور باید فقط به مشتری متصل باشد.');
            }
        });
    }
}
