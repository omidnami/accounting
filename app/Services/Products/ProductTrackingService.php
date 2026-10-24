<?php

namespace App\Services\Products;

use App\Enums\ProductSerialStatus;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductTrackingService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createBatch(Product $product, array $data): ProductBatch
    {
        $this->assertTrackableProduct($product);

        if (! $product->track_batches) {
            throw new DomainException('برای این کالا کنترل Batch فعال نیست.');
        }

        return ProductBatch::query()->create([
            'product_id' => $product->id,
            'batch_number' => trim((string) $data['batch_number']),
            'production_date' => $data['production_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<int, string>  $serialNumbers
     */
    public function createSerials(
        Product $product,
        array $serialNumbers,
        ?int $batchId,
        ?int $warehouseId,
        ?string $acquiredAt,
        ?string $notes,
    ): int {
        $this->assertTrackableProduct($product);

        if (! $product->track_serials) {
            throw new DomainException('برای این کالا کنترل شماره سریال فعال نیست.');
        }
        $batch = $batchId === null
            ? null
            : ProductBatch::query()
                ->whereKey($batchId)
                ->where('product_id', $product->id)
                ->firstOrFail();
        $warehouse = $warehouseId === null
            ? null
            : Warehouse::query()->whereKey($warehouseId)->where('is_active', true)->firstOrFail();
        $serialNumbers = collect($serialNumbers)
            ->map(fn (string $serial): string => trim($serial))
            ->filter()
            ->unique()
            ->values();

        if ($serialNumbers->isEmpty()) {
            throw new DomainException('حداقل یک شماره سریال وارد کنید.');
        }

        $duplicates = ProductSerial::query()
            ->whereIn('serial_number', $serialNumbers->all())
            ->pluck('serial_number');

        if ($duplicates->isNotEmpty()) {
            throw new DomainException('شماره سریال تکراری: '.$duplicates->take(3)->implode('، '));
        }

        DB::transaction(function () use (
            $product,
            $batch,
            $warehouse,
            $serialNumbers,
            $acquiredAt,
            $notes,
        ): void {
            foreach ($serialNumbers as $serialNumber) {
                ProductSerial::query()->create([
                    'product_id' => $product->id,
                    'product_batch_id' => $batch?->id,
                    'warehouse_id' => $warehouse?->id,
                    'serial_number' => $serialNumber,
                    'status' => ProductSerialStatus::Available,
                    'acquired_at' => $acquiredAt,
                    'notes' => $notes,
                ]);
            }
        }, attempts: 3);

        return $serialNumbers->count();
    }

    public function voidSerial(ProductSerial $serial, User $actor): ProductSerial
    {
        if ($serial->status !== ProductSerialStatus::Available) {
            throw new DomainException('فقط سریال موجود قابل ابطال است.');
        }

        $serial->forceFill([
            'status' => ProductSerialStatus::Void,
            'notes' => trim(($serial->notes ? $serial->notes."\n" : '').'ابطال توسط '.$actor->name),
        ])->save();

        return $serial->refresh();
    }

    public function assertTrackableProduct(Product $product): void
    {
        if ($product->variants()->exists()) {
            throw new DomainException('برای کالای والد تنوع، Batch یا سریال ثبت نمی‌شود؛ تنوع را انتخاب کنید.');
        }
    }
}
