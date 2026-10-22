<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'purchase_price',
        'sale_price',
        'tax_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
