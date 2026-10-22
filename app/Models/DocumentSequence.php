<?php

namespace App\Models;

use App\Enums\DocumentType;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class DocumentSequence extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'fiscal_year_id',
        'document_type',
        'prefix',
        'next_number',
        'padding',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'next_number' => 'integer',
            'padding' => 'integer',
            'is_active' => 'boolean',
        ];
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
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeFor(
        Builder $query,
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        DocumentType $type,
    ): Builder {
        return $query->where([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'document_type' => $type,
        ]);
    }

    public function allocate(): string
    {
        return DB::transaction(function (): string {
            $sequence = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $sequence->is_active) {
                throw new DomainException('شماره‌گذاری این نوع سند غیرفعال است.');
            }

            $number = $sequence->next_number;
            $sequence->next_number++;
            $sequence->save();

            return sprintf(
                '%s%0'.$sequence->padding.'d',
                $sequence->prefix,
                $number,
            );
        });
    }
}
