<?php

namespace App\Services\Products;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Enums\JournalEntryKind;
use App\Enums\ProductSerialStatus;
use App\Models\InventoryMovementSerial;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductTrackingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AccountingPeriodLockService $periods,
    ) {}

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
        return DB::transaction(function () use ($serial, $actor): ProductSerial {
            $locked = ProductSerial::query()
                ->whereKey($serial->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ProductSerialStatus::Available) {
                throw new DomainException('فقط سریال موجود قابل ابطال است.');
            }

            $enteredInventory = InventoryMovementSerial::query()
                ->where('product_serial_id', $locked->id)
                ->whereHas(
                    'movement',
                    fn ($query) => $query->where('direction', InventoryDirection::In),
                )
                ->lockForUpdate()
                ->exists();
            $note = trim(
                ($locked->notes ? $locked->notes."\n" : '')
                .'ابطال توسط '.$actor->name,
            );

            if (! $enteredInventory) {
                $locked->forceFill([
                    'status' => ProductSerialStatus::Void,
                    'notes' => $note,
                ])->save();

                return $locked->refresh();
            }

            if ($locked->warehouse_id === null) {
                throw new DomainException('سریال واردشده برای ابطال باید انبار داشته باشد.');
            }

            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $context = $this->periods->lockCurrentOpenPeriod(
                (int) $warehouse->store_id,
                (int) $warehouse->branch_id,
                today()->toDateString(),
            );
            $movement = $this->inventory->issue(
                warehouseId: $warehouse->id,
                productId: (int) $locked->product_id,
                quantity: 1,
                movementDate: today()->toDateString(),
                idempotencyKey: "serial-void:{$locked->id}",
                movementType: InventoryMovementType::Adjustment,
                source: $locked,
                createdBy: $actor->id,
                reference: $locked->serial_number,
                description: 'ابطال سریال و خروج از موجودی',
                serialIds: [$locked->id],
            );

            if (Decimal::isPositive($movement->total_cost)) {
                $this->posting->post(
                    [
                        'store_id' => $warehouse->store_id,
                        'branch_id' => $warehouse->branch_id,
                        'fiscal_year_id' => $context['fiscal_year']->id,
                        'accounting_period_id' => $context['period']->id,
                        'entry_kind' => JournalEntryKind::System,
                        'entry_date' => today()->toDateString(),
                        'description' => "ابطال سریال {$locked->serial_number}",
                        'source_type' => $locked->getMorphClass(),
                        'source_id' => $locked->id,
                        'created_by' => $actor->id,
                    ],
                    [
                        [
                            'account_id' => $this->settings->defaultAccountId(
                                'inventory_adjustment_loss',
                                (int) $warehouse->store_id,
                            ),
                            'debit' => $movement->total_cost,
                            'credit' => '0.00',
                            'description' => 'کسری موجودی از ابطال سریال',
                        ],
                        [
                            'account_id' => $this->settings->defaultAccountId(
                                'inventory',
                                (int) $warehouse->store_id,
                            ),
                            'debit' => '0.00',
                            'credit' => $movement->total_cost,
                            'description' => 'کاهش موجودی از ابطال سریال',
                        ],
                    ],
                    $actor,
                );
            }

            $locked->refresh()->forceFill([
                'status' => ProductSerialStatus::Void,
                'notes' => $note,
            ])->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    public function assertTrackableProduct(Product $product): void
    {
        if ($product->variants()->exists()) {
            throw new DomainException('برای کالای والد تنوع، Batch یا سریال ثبت نمی‌شود؛ تنوع را انتخاب کنید.');
        }
    }
}
