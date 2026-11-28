<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Enums\ProductSerialStatus;
use App\Models\InventoryBalance;
use App\Models\InventoryBatchBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\InventoryMovementSerial;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Warehouse;
use App\Support\Decimal;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * @param  array<int, string>  $serialNumbers
     * @param  array<int, int>  $serialIds
     * @param  array<int, array{product_batch_id: int, quantity: string|int|float, unit_cost?: string|int|float, total_cost?: string|int|float}>  $batchAllocations
     */
    public function receive(
        int $warehouseId,
        int $productId,
        string|int|float $quantity,
        string|int|float $unitCost,
        string $movementDate,
        string $idempotencyKey,
        InventoryMovementType $movementType = InventoryMovementType::Purchase,
        ?Model $source = null,
        ?int $createdBy = null,
        ?string $reference = null,
        ?string $description = null,
        string|int|float|null $exactTotalCost = null,
        ?int $productBatchId = null,
        array $serialNumbers = [],
        array $serialIds = [],
        array $batchAllocations = [],
    ): InventoryMovement {
        return DB::transaction(fn (): InventoryMovement => $this->move(
            warehouseId: $warehouseId,
            productId: $productId,
            direction: InventoryDirection::In,
            movementType: $movementType,
            quantity: $quantity,
            unitCost: $unitCost,
            movementDate: $movementDate,
            idempotencyKey: $idempotencyKey,
            source: $source,
            createdBy: $createdBy,
            reference: $reference,
            description: $description,
            exactTotalCost: $exactTotalCost,
            productBatchId: $productBatchId,
            serialNumbers: $serialNumbers,
            batchAllocations: $batchAllocations,
            serialIds: $serialIds,
        ), attempts: 3);
    }

    /**
     * @param  array<int, int>  $serialIds
     * @param  array<int, array{product_batch_id: int, quantity: string}>  $batchAllocations
     */
    public function issue(
        int $warehouseId,
        int $productId,
        string|int|float $quantity,
        string $movementDate,
        string $idempotencyKey,
        InventoryMovementType $movementType = InventoryMovementType::Sale,
        ?Model $source = null,
        ?int $createdBy = null,
        ?string $reference = null,
        ?string $description = null,
        array $serialIds = [],
        array $batchAllocations = [],
    ): InventoryMovement {
        return DB::transaction(fn (): InventoryMovement => $this->move(
            warehouseId: $warehouseId,
            productId: $productId,
            direction: InventoryDirection::Out,
            movementType: $movementType,
            quantity: $quantity,
            unitCost: null,
            movementDate: $movementDate,
            idempotencyKey: $idempotencyKey,
            source: $source,
            createdBy: $createdBy,
            reference: $reference,
            description: $description,
            exactTotalCost: null,
            productBatchId: null,
            serialNumbers: [],
            batchAllocations: $batchAllocations,
            serialIds: $serialIds,
        ), attempts: 3);
    }

    /**
     * @return array{out: InventoryMovement, in: InventoryMovement}
     */
    public function transfer(
        int $sourceWarehouseId,
        int $destinationWarehouseId,
        int $productId,
        string|int|float $quantity,
        string $movementDate,
        string $idempotencyKey,
        ?int $createdBy = null,
        ?string $reference = null,
        ?string $description = null,
        ?Model $source = null,
    ): array {
        if ($sourceWarehouseId === $destinationWarehouseId) {
            throw new DomainException('انبار مبدأ و مقصد انتقال نباید یکسان باشند.');
        }

        return DB::transaction(function () use (
            $sourceWarehouseId,
            $destinationWarehouseId,
            $productId,
            $quantity,
            $movementDate,
            $idempotencyKey,
            $createdBy,
            $reference,
            $description,
            $source,
        ): array {
            Warehouse::query()
                ->whereIn('id', [$sourceWarehouseId, $destinationWarehouseId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $out = $this->move(
                warehouseId: $sourceWarehouseId,
                productId: $productId,
                direction: InventoryDirection::Out,
                movementType: InventoryMovementType::Transfer,
                quantity: $quantity,
                unitCost: null,
                movementDate: $movementDate,
                idempotencyKey: $idempotencyKey.':out',
                source: $source,
                createdBy: $createdBy,
                reference: $reference,
                description: $description,
                exactTotalCost: null,
                productBatchId: null,
                serialNumbers: [],
                batchAllocations: [],
                serialIds: [],
            );

            $batchAllocations = InventoryMovementBatch::query()
                ->where('inventory_movement_id', $out->id)
                ->get(['product_batch_id', 'quantity', 'unit_cost', 'total_cost'])
                ->map(fn (InventoryMovementBatch $allocation): array => [
                    'product_batch_id' => $allocation->product_batch_id,
                    'quantity' => $allocation->quantity,
                    'unit_cost' => $allocation->unit_cost,
                    'total_cost' => $allocation->total_cost,
                ])
                ->all();
            $serialIds = $out->serialAssignments()
                ->pluck('product_serial_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $in = $this->move(
                warehouseId: $destinationWarehouseId,
                productId: $productId,
                direction: InventoryDirection::In,
                movementType: InventoryMovementType::Transfer,
                quantity: $quantity,
                unitCost: $out->unit_cost,
                movementDate: $movementDate,
                idempotencyKey: $idempotencyKey.':in',
                source: $source,
                createdBy: $createdBy,
                reference: $reference,
                description: $description,
                exactTotalCost: $out->total_cost,
                productBatchId: null,
                serialNumbers: [],
                batchAllocations: $batchAllocations,
                serialIds: $serialIds,
            );

            return ['out' => $out, 'in' => $in];
        }, attempts: 3);
    }

    /**
     * @param  array<int, string>  $serialNumbers
     * @param  array<int, array{product_batch_id: int, quantity: string|int|float, unit_cost?: string|int|float, total_cost?: string|int|float}>  $batchAllocations
     * @param  array<int, int>  $serialIds
     */
    private function move(
        int $warehouseId,
        int $productId,
        InventoryDirection $direction,
        InventoryMovementType $movementType,
        string|int|float $quantity,
        string|int|float|null $unitCost,
        string $movementDate,
        string $idempotencyKey,
        ?Model $source,
        ?int $createdBy,
        ?string $reference,
        ?string $description,
        string|int|float|null $exactTotalCost,
        ?int $productBatchId,
        array $serialNumbers,
        array $batchAllocations,
        array $serialIds,
    ): InventoryMovement {
        $normalizedQuantity = Decimal::normalize($quantity, Decimal::QUANTITY_SCALE);
        $normalizedMovementDate = CarbonImmutable::parse($movementDate)->toDateString();
        $requestPayload = $this->movementRequestPayload(
            warehouseId: $warehouseId,
            productId: $productId,
            direction: $direction,
            movementType: $movementType,
            quantity: $normalizedQuantity,
            movementDate: $normalizedMovementDate,
            source: $source,
            createdBy: $createdBy,
            reference: $reference,
            description: $description,
            unitCost: $unitCost,
            exactTotalCost: $exactTotalCost,
            productBatchId: $productBatchId,
            serialNumbers: $serialNumbers,
            serialIds: $serialIds,
            batchAllocations: $batchAllocations,
        );
        $requestHash = IdempotencyHasher::hash('inventory.movement', $requestPayload);

        $existing = InventoryMovement::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $this->assertIdempotentMatch($existing, $requestHash, $requestPayload);

            return $existing;
        }

        if (! $movementType->supports($direction)) {
            throw new DomainException('نوع حرکت با جهت ورود یا خروج انبار سازگار نیست.');
        }

        $warehouse = Warehouse::query()
            ->with('branch')
            ->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            throw new DomainException('انبار انتخاب‌شده غیرفعال است.');
        }

        $product = Product::query()->findOrFail($productId);

        if (! $product->is_active && ! in_array($movementType, [
            InventoryMovementType::SalesReturn,
            InventoryMovementType::PurchaseReturn,
        ], true)) {
            throw new DomainException('کالای انتخاب‌شده غیرفعال است.');
        }

        if (! $product->isStockItem()) {
            throw new DomainException('کالای غیرانباری و خدمت حرکت انبار ندارند.');
        }

        if ($product->variants()->exists()) {
            throw new DomainException('کالای والد تنوع موجودی مستقل ندارد؛ یکی از تنوع‌ها را انتخاب کنید.');
        }

        if (! Decimal::isPositive($normalizedQuantity)) {
            throw new DomainException('مقدار حرکت انبار باید بزرگ‌تر از صفر باشد.');
        }

        $laterMovement = InventoryMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where(
                'movement_date',
                '>=',
                CarbonImmutable::parse($normalizedMovementDate)->addDay()->toDateString(),
            )
            ->orderBy('movement_date')
            ->lockForUpdate()
            ->first();

        if ($laterMovement) {
            throw new DomainException('حرکت انبار نمی‌تواند عقب‌تر از آخرین گردش همان کالا ثبت شود.');
        }

        InventoryBalance::query()->insertOrIgnore([
            'store_id' => $warehouse->store_id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 0,
            'average_cost' => 0,
            'inventory_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $balance = InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->firstOrFail();

        [$movementQuantity, $movementUnitCost, $movementTotalCost, $quantityAfter, $averageCostAfter, $valueAfter] =
            $direction === InventoryDirection::In
                ? $this->calculateInbound(
                    $balance,
                    $normalizedQuantity,
                    $unitCost,
                    $exactTotalCost,
                )
                : $this->calculateOutbound($balance, $normalizedQuantity);

        $balance->forceFill([
            'quantity' => $quantityAfter,
            'average_cost' => $averageCostAfter,
            'inventory_value' => $valueAfter,
            'last_movement_at' => now(),
        ])->save();

        $movement = InventoryMovement::query()->create([
            'store_id' => $warehouse->store_id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'direction' => $direction,
            'movement_type' => $movementType,
            'quantity' => $movementQuantity,
            'unit_cost' => $movementUnitCost,
            'total_cost' => $movementTotalCost,
            'quantity_after' => $quantityAfter,
            'average_cost_after' => $averageCostAfter,
            'value_after' => $valueAfter,
            'movement_date' => $normalizedMovementDate,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'idempotency_request_hash' => $requestHash,
            'created_by' => $createdBy,
            'reference' => $reference,
            'description' => $description,
        ]);

        if ($direction === InventoryDirection::In) {
            $this->assignInboundTracking(
                movement: $movement,
                product: $product,
                warehouse: $warehouse,
                quantity: $movementQuantity,
                totalCost: $movementTotalCost,
                movementType: $movementType,
                batchId: $productBatchId,
                serialNumbers: $serialNumbers,
                batchAllocations: $batchAllocations,
                serialIds: $serialIds,
            );
        } else {
            $this->assignOutboundTracking(
                movement: $movement,
                product: $product,
                warehouse: $warehouse,
                quantity: $movementQuantity,
                totalCost: $movementTotalCost,
                movementDate: $normalizedMovementDate,
                movementType: $movementType,
                serialIds: $serialIds,
                batchAllocations: $batchAllocations,
            );
        }

        $this->assertPersistedTrackingMatches($movement, $product);

        return $movement;
    }

    /**
     * @param  array<int, string>  $serialNumbers
     * @param  array<int, array{product_batch_id: int, quantity: string|int|float, unit_cost?: string|int|float, total_cost?: string|int|float}>  $batchAllocations
     * @param  array<int, int>  $serialIds
     */
    private function assignInboundTracking(
        InventoryMovement $movement,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $totalCost,
        InventoryMovementType $movementType,
        ?int $batchId,
        array $serialNumbers,
        array $batchAllocations,
        array $serialIds,
    ): void {
        $normalizedSerialNumbers = [];
        $seenSerialNumbers = [];

        if ($serialIds !== [] && ! in_array($movementType, [
            InventoryMovementType::Transfer,
            InventoryMovementType::SalesReturn,
        ], true)) {
            throw new DomainException('ورود سریال با شناسه فقط برای انتقال یا برگشت فروش مجاز است.');
        }

        $serialIdCount = count($serialIds);
        $serialIds = array_values(array_unique(array_map('intval', $serialIds)));

        if ($serialIds !== [] && count($serialIds) !== $serialIdCount) {
            throw new DomainException('سریال‌های ورودی تکراری هستند.');
        }

        foreach ($serialNumbers as $serialNumber) {
            $serialNumber = trim((string) $serialNumber);

            if ($serialNumber === '') {
                throw new DomainException('شماره سریال نمی‌تواند خالی باشد.');
            }

            if (isset($seenSerialNumbers[$serialNumber])) {
                throw new DomainException("سریال {$serialNumber} تکراری است.");
            }

            $seenSerialNumbers[$serialNumber] = true;
            $normalizedSerialNumbers[] = $serialNumber;
        }

        $serialNumbers = $normalizedSerialNumbers;

        if (! $product->track_batches && ! $product->track_serials) {
            if ($batchId !== null || $serialNumbers !== [] || $serialIds !== [] || $batchAllocations !== []) {
                throw new DomainException('کالای بدون ردیابی سری ساخت یا سریال نمی‌پذیرد.');
            }

            return;
        }

        if ($product->track_batches
            && $batchAllocations === []
            && $serialIds !== []) {
            $serials = ProductSerial::query()
                ->whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();

            if ($serials->count() !== count($serialIds)
                || $serials->contains(fn (ProductSerial $serial): bool => $serial->product_batch_id === null)) {
                throw new DomainException('سریال‌های برگشتی باید سری ساخت معتبر داشته باشند.');
            }

            $remainingCost = $totalCost;
            $groups = $serials
                ->groupBy(fn (ProductSerial $serial): int => (int) $serial->product_batch_id)
                ->values();
            $batchAllocations = $groups->map(function ($group, $index) use (
                $quantity,
                $totalCost,
                &$remainingCost,
                $groups,
            ): array {
                $groupQuantity = number_format($group->count(), 3, '.', '');
                $groupCost = $index === $groups->count() - 1
                    ? $remainingCost
                    : Decimal::divide(
                        Decimal::multiply($totalCost, $groupQuantity, 4),
                        $quantity,
                        2,
                    );
                $remainingCost = Decimal::subtract($remainingCost, $groupCost, 2);

                return [
                    'product_batch_id' => (int) $group->first()->product_batch_id,
                    'quantity' => $groupQuantity,
                    'unit_cost' => Decimal::divide($groupCost, $groupQuantity, 4),
                    'total_cost' => $groupCost,
                ];
            })->all();
        }

        if ($product->track_batches && $batchAllocations === []) {
            $batch = $batchId !== null
                ? $this->productBatch($product, $batchId)
                : $this->syntheticBatch($product, $movementType, $movement->movement_date->toDateString());
            $batchAllocations = [[
                'product_batch_id' => $batch->id,
                'quantity' => $quantity,
                'unit_cost' => Decimal::divide($totalCost, $quantity, Decimal::UNIT_COST_SCALE),
                'total_cost' => $totalCost,
            ]];
        }
        $batchAllocations = $this->normalizeBatchAllocations(
            $product,
            $batchAllocations,
            $quantity,
            $totalCost,
        );
        $trackingBatchId = null;

        foreach ($batchAllocations as $allocation) {
            $batch = $this->productBatch($product, (int) $allocation['product_batch_id']);
            $batchQuantity = $allocation['quantity'];
            $trackingBatchId ??= $batch->id;
            InventoryBatchBalance::query()->insertOrIgnore([
                'store_id' => $warehouse->store_id,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'product_batch_id' => $batch->id,
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $batchBalance = InventoryBatchBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_batch_id', $batch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $batchBalance->forceFill([
                'quantity' => Decimal::add(
                    $batchBalance->quantity,
                    $batchQuantity,
                    Decimal::QUANTITY_SCALE,
                ),
            ])->save();
            InventoryMovementBatch::query()->create([
                'inventory_movement_id' => $movement->id,
                'product_batch_id' => $batch->id,
                'quantity' => $batchQuantity,
                'unit_cost' => $allocation['unit_cost'],
                'total_cost' => $allocation['total_cost'],
            ]);
        }

        if ($product->track_serials) {
            $this->assertWholeSerialQuantity($quantity);

            if ($serialNumbers !== []) {
                $batch = $trackingBatchId === null
                    ? null
                    : $this->productBatch($product, $trackingBatchId);

                foreach ($serialNumbers as $serialNumber) {
                    $serialNumber = trim((string) $serialNumber);

                    if ($serialNumber === '') {
                        continue;
                    }

                    $serial = ProductSerial::query()
                        ->where('serial_number', $serialNumber)
                        ->lockForUpdate()
                        ->first();

                    if ($serial && (int) $serial->product_id !== (int) $product->id) {
                        throw new DomainException("سریال {$serialNumber} متعلق به کالای دیگری است.");
                    }

                    if ($serial?->product_batch_id !== null
                        && $batch !== null
                        && (int) $serial->product_batch_id !== (int) $batch->id) {
                        throw new DomainException("سری ساخت سریال {$serialNumber} با فاکتور سازگار نیست.");
                    }

                    if ($serial === null) {
                        $serial = ProductSerial::query()->create([
                            'product_id' => $product->id,
                            'product_batch_id' => $batch?->id,
                            'warehouse_id' => $warehouse->id,
                            'serial_number' => $serialNumber,
                            'status' => ProductSerialStatus::Available,
                            'acquired_at' => $movement->movement_date->toDateString(),
                        ]);
                    } elseif ($movementType !== InventoryMovementType::SalesReturn) {
                        $hasMovement = InventoryMovementSerial::query()
                            ->where('product_serial_id', $serial->id)
                            ->exists();

                        if ($serial->status !== ProductSerialStatus::Available || $hasMovement) {
                            throw new DomainException("سریال {$serialNumber} قبلاً ثبت شده است.");
                        }
                    }

                    if (in_array($serial->id, $serialIds, true)) {
                        throw new DomainException("سریال {$serialNumber} تکراری است.");
                    }

                    $serialIds[] = $serial->id;
                }
            }

            if ((int) round((float) $quantity) !== count($serialIds)
                || count($serialIds) === 0) {
                throw new DomainException('تعداد سریال‌های ورودی با مقدار کالا برابر نیست.');
            }

            foreach (array_unique($serialIds) as $serialId) {
                $serial = ProductSerial::query()
                    ->whereKey($serialId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($serial->status === ProductSerialStatus::Sold
                    && $movementType !== InventoryMovementType::SalesReturn) {
                    throw new DomainException("سریال {$serial->serial_number} قبلاً فروخته شده است.");
                }

                $serial->forceFill([
                    'product_batch_id' => $serial->product_batch_id ?? $trackingBatchId,
                    'warehouse_id' => $warehouse->id,
                    'status' => ProductSerialStatus::Available,
                    'sold_at' => null,
                ])->save();
                $movement->serialAssignments()->firstOrCreate([
                    'product_serial_id' => $serial->id,
                ]);
            }
        } elseif ($serialIds !== []) {
            throw new DomainException('ثبت سریال برای این کالا فعال نیست.');
        }
    }

    /**
     * @param  array<int, array{product_batch_id: int, quantity: string|int|float, unit_cost?: string|int|float, total_cost?: string|int|float}>  $allocations
     * @return array<int, array{product_batch_id: int, quantity: string, unit_cost: string, total_cost: string}>
     */
    private function normalizeBatchAllocations(
        Product $product,
        array $allocations,
        string $quantity,
        string $totalCost,
    ): array {
        if ($allocations === []) {
            return [];
        }

        if (! $product->track_batches) {
            throw new DomainException('تخصیص سری ساخت برای کالای بدون ردیابی سری ساخت مجاز نیست.');
        }

        $normalized = [];
        $seenBatchIds = [];

        foreach ($allocations as $allocation) {
            $batchId = (int) $allocation['product_batch_id'];

            if ($batchId <= 0 || isset($seenBatchIds[$batchId])) {
                throw new DomainException('تخصیص‌های سری ساخت باید شناسهٔ معتبر و یکتا داشته باشند.');
            }

            $seenBatchIds[$batchId] = true;
            $allocationQuantity = Decimal::normalize($allocation['quantity'], Decimal::QUANTITY_SCALE);

            if (! Decimal::isPositive($allocationQuantity)) {
                throw new DomainException('مقدار تخصیص سری ساخت باید مثبت باشد.');
            }

            $hasTotalCost = isset($allocation['total_cost']);
            $allocationTotalCost = $hasTotalCost
                ? Decimal::normalize($allocation['total_cost'], Decimal::MONEY_SCALE)
                : null;

            if ($allocationTotalCost !== null && ! Decimal::isPositive($allocationTotalCost)) {
                throw new DomainException('هزینهٔ تخصیص سری ساخت باید مثبت باشد.');
            }

            $normalized[] = [
                'product_batch_id' => $batchId,
                'quantity' => $allocationQuantity,
                'unit_cost' => isset($allocation['unit_cost'])
                    ? Decimal::normalize($allocation['unit_cost'], Decimal::UNIT_COST_SCALE)
                    : null,
                'total_cost' => $allocationTotalCost,
            ];
        }

        usort($normalized, fn (array $left, array $right): int => $left['product_batch_id'] <=> $right['product_batch_id']);
        $allocatedQuantity = '0.000';

        foreach ($normalized as $allocation) {
            $allocatedQuantity = Decimal::add($allocatedQuantity, $allocation['quantity'], Decimal::QUANTITY_SCALE);
        }

        if (Decimal::compare($allocatedQuantity, $quantity, Decimal::QUANTITY_SCALE) !== 0) {
            throw new DomainException('جمع مقدار تخصیص‌های سری ساخت با مقدار حرکت انبار برابر نیست.');
        }

        $hasExplicitCosts = collect($normalized)->every(
            fn (array $allocation): bool => $allocation['total_cost'] !== null,
        );

        if ($hasExplicitCosts) {
            $allocatedCost = '0.00';

            foreach ($normalized as $allocation) {
                $allocatedCost = Decimal::add($allocatedCost, $allocation['total_cost'], Decimal::MONEY_SCALE);
            }

            if (Decimal::compare($allocatedCost, $totalCost, Decimal::MONEY_SCALE) !== 0) {
                throw new DomainException('جمع هزینهٔ تخصیص‌های سری ساخت با هزینهٔ حرکت انبار برابر نیست.');
            }
        } else {
            $normalized = $this->allocateBatchCosts($normalized, $quantity, $totalCost);
        }

        foreach ($normalized as &$allocation) {
            $allocation['unit_cost'] ??= Decimal::divide(
                $allocation['total_cost'],
                $allocation['quantity'],
                Decimal::UNIT_COST_SCALE,
            );
        }

        unset($allocation);

        return $normalized;
    }

    /**
     * @param  array<int, array{product_batch_id: int, quantity: string, unit_cost: string|null, total_cost: string|null}>  $allocations
     * @return array<int, array{product_batch_id: int, quantity: string, unit_cost: string|null, total_cost: string}>
     */
    private function allocateBatchCosts(array $allocations, string $quantity, string $totalCost): array
    {
        $allocatedCost = '0.00';
        $cumulativeQuantity = '0.000';
        $lastIndex = count($allocations) - 1;

        foreach ($allocations as $index => &$allocation) {
            $cumulativeQuantity = Decimal::add(
                $cumulativeQuantity,
                $allocation['quantity'],
                Decimal::QUANTITY_SCALE,
            );
            $cumulativeCost = Decimal::divide(
                Decimal::multiply($totalCost, $cumulativeQuantity, 4),
                $quantity,
                Decimal::MONEY_SCALE,
            );
            $allocation['total_cost'] = $index === $lastIndex
                ? Decimal::subtract($totalCost, $allocatedCost, Decimal::MONEY_SCALE)
                : Decimal::subtract($cumulativeCost, $allocatedCost, Decimal::MONEY_SCALE);

            if (! Decimal::isPositive($allocation['total_cost'])) {
                throw new DomainException('هزینهٔ تخصیص سری ساخت پس از گردکردن باید مثبت بماند.');
            }

            $allocatedCost = Decimal::add($allocatedCost, $allocation['total_cost'], Decimal::MONEY_SCALE);
        }

        unset($allocation);

        return $allocations;
    }

    private function assertPersistedTrackingMatches(InventoryMovement $movement, Product $product): void
    {
        $batchAssignments = $movement->batchAssignments()->get(['quantity', 'total_cost']);

        if ($product->track_batches) {
            if ($batchAssignments->isEmpty()) {
                throw new DomainException('حرکت کالای دارای سری ساخت باید تخصیص سری ساخت داشته باشد.');
            }

            $quantity = '0.000';
            $totalCost = '0.00';

            foreach ($batchAssignments as $assignment) {
                $quantity = Decimal::add($quantity, $assignment->quantity, Decimal::QUANTITY_SCALE);
                $totalCost = Decimal::add($totalCost, $assignment->total_cost, Decimal::MONEY_SCALE);
            }

            if (Decimal::compare($quantity, $movement->quantity, Decimal::QUANTITY_SCALE) !== 0
                || Decimal::compare($totalCost, $movement->total_cost, Decimal::MONEY_SCALE) !== 0) {
                throw new DomainException('تخصیص‌های ثبت‌شدهٔ سری ساخت با حرکت انبار سازگار نیستند.');
            }
        } elseif ($batchAssignments->isNotEmpty()) {
            throw new DomainException('کالای بدون ردیابی سری ساخت نباید تخصیص سری ساخت داشته باشد.');
        }

        $serialCount = $movement->serialAssignments()->count();

        if ($product->track_serials) {
            if ($serialCount !== (int) round((float) $movement->quantity)) {
                throw new DomainException('تعداد سریال‌های ثبت‌شده با مقدار حرکت انبار برابر نیست.');
            }
        } elseif ($serialCount !== 0) {
            throw new DomainException('کالای بدون ردیابی سریال نباید تخصیص سریال داشته باشد.');
        }
    }

    /**
     * @param  array<int, int>  $serialIds
     * @param  array<int, array{product_batch_id: int, quantity: string|int|float}>  $batchAllocations
     */
    private function assignOutboundTracking(
        InventoryMovement $movement,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $totalCost,
        string $movementDate,
        InventoryMovementType $movementType,
        array $serialIds,
        array $batchAllocations,
    ): void {
        $selectedSerials = collect();

        if ($product->track_serials) {
            $this->assertWholeSerialQuantity($quantity);
            $selectedSerials = $this->serialSelection(
                $product,
                $warehouse,
                $quantity,
                $movementDate,
                $movementType,
                $serialIds,
            );
        } elseif ($serialIds !== []) {
            throw new DomainException('این کالا بر اساس سریال کنترل نمی‌شود.');
        }

        if ($product->track_batches) {
            if ($selectedSerials->contains(
                fn (ProductSerial $serial): bool => $serial->product_batch_id === null,
            )) {
                throw new DomainException('سریال انتخاب‌شده سری ساخت ندارد.');
            }
            $allocations = $batchAllocations !== []
                ? $batchAllocations
                : ($selectedSerials->isNotEmpty()
                ? $selectedSerials
                    ->groupBy(fn (ProductSerial $serial): int => (int) $serial->product_batch_id)
                    ->map(fn ($serials, $batchId): array => [
                        'product_batch_id' => (int) $batchId,
                        'quantity' => number_format($serials->count(), 3, '.', ''),
                    ])
                    ->values()
                    ->all()
                : $this->batchSelection(
                    $product,
                    $warehouse,
                    $quantity,
                    $movementDate,
                    $movementType,
                ));

            $allocations = $this->normalizeBatchAllocations(
                $product,
                $allocations,
                $quantity,
                $totalCost,
            );

            foreach ($allocations as $allocation) {
                $batch = $this->productBatch($product, (int) $allocation['product_batch_id']);
                $allocationQuantity = $allocation['quantity'];
                $batchBalance = InventoryBatchBalance::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_batch_id', $batch->id)
                    ->lockForUpdate()
                    ->first();

                if (! $batchBalance
                    || Decimal::compare($batchBalance->quantity, $allocationQuantity, 3) < 0) {
                    throw new DomainException("موجودی سری ساخت {$batch->batch_number} کافی نیست.");
                }

                $batchBalance->forceFill([
                    'quantity' => Decimal::subtract(
                        $batchBalance->quantity,
                        $allocationQuantity,
                        3,
                    ),
                ])->save();
                InventoryMovementBatch::query()->create([
                    'inventory_movement_id' => $movement->id,
                    'product_batch_id' => $batch->id,
                    'quantity' => $allocationQuantity,
                    'unit_cost' => $allocation['unit_cost'],
                    'total_cost' => $allocation['total_cost'],
                ]);
            }
        } elseif ($batchAllocations !== []) {
            throw new DomainException('تخصیص سری ساخت برای کالای بدون ردیابی سری ساخت مجاز نیست.');
        }

        if ($product->track_serials) {
            $nextStatus = match ($movementType) {
                InventoryMovementType::Sale => ProductSerialStatus::Sold,
                InventoryMovementType::PurchaseReturn => ProductSerialStatus::Returned,
                InventoryMovementType::Adjustment => ProductSerialStatus::Void,
                InventoryMovementType::Transfer => ProductSerialStatus::Available,
                default => ProductSerialStatus::Available,
            };

            foreach ($selectedSerials as $serial) {
                $serial->forceFill([
                    'status' => $nextStatus,
                    'sold_at' => $movementType === InventoryMovementType::Sale
                        ? $movementDate
                        : $serial->sold_at,
                    'warehouse_id' => $movementType === InventoryMovementType::PurchaseReturn
                        ? null
                        : $warehouse->id,
                ])->save();
                $movement->serialAssignments()->firstOrCreate([
                    'product_serial_id' => $serial->id,
                ]);
            }
        }
    }

    /**
     * @param  array<int, int>  $serialIds
     * @return Collection<int, ProductSerial>
     */
    private function serialSelection(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $movementDate,
        InventoryMovementType $movementType,
        array $serialIds,
    ) {
        $count = (int) round((float) $quantity);
        $query = ProductSerial::query()
            ->select('product_serials.*')
            ->leftJoin('product_batches', 'product_batches.id', '=', 'product_serials.product_batch_id')
            ->where('product_serials.product_id', $product->id)
            ->where('product_serials.status', ProductSerialStatus::Available->value)
            ->when(
                $serialIds !== [],
                fn ($query) => $query->whereIn('product_serials.id', $serialIds),
                fn ($query) => $query->where('product_serials.warehouse_id', $warehouse->id),
            )
            ->when(
                ! in_array($movementType, [
                    InventoryMovementType::PurchaseReturn,
                    InventoryMovementType::Adjustment,
                ], true),
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('product_batches.expiry_date')
                    ->orWhereDate('product_batches.expiry_date', '>=', $movementDate)),
            )
            ->orderByRaw('CASE WHEN product_batches.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('product_batches.expiry_date')
            ->orderBy('product_serials.acquired_at')
            ->orderBy('product_serials.id')
            ->lockForUpdate()
            ->limit($count);
        $serials = $query->get();

        if ($serials->count() !== $count) {
            throw new DomainException('تعداد سریال موجود قابل برداشت با مقدار فروش برابر نیست.');
        }

        if ($serials->contains(
            fn (ProductSerial $serial): bool => (int) $serial->warehouse_id !== (int) $warehouse->id,
        )) {
            throw new DomainException('سریال انتخاب‌شده در این انبار موجود نیست.');
        }

        return $serials;
    }

    /**
     * @return array<int, array{product_batch_id: int, quantity: string}>
     */
    private function batchSelection(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $movementDate,
        InventoryMovementType $movementType,
    ): array {
        $remaining = Decimal::normalize($quantity, 3);
        $balances = InventoryBatchBalance::query()
            ->select('inventory_batch_balances.*')
            ->join('product_batches', 'product_batches.id', '=', 'inventory_batch_balances.product_batch_id')
            ->where('inventory_batch_balances.warehouse_id', $warehouse->id)
            ->where('product_batches.product_id', $product->id)
            ->where('inventory_batch_balances.quantity', '>', 0)
            ->when(
                ! in_array($movementType, [
                    InventoryMovementType::PurchaseReturn,
                    InventoryMovementType::Adjustment,
                ], true),
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('product_batches.expiry_date')
                    ->orWhereDate('product_batches.expiry_date', '>=', $movementDate)),
            )
            ->orderByRaw('CASE WHEN product_batches.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('product_batches.expiry_date')
            ->orderBy('product_batches.id')
            ->lockForUpdate()
            ->get();
        $allocations = [];

        foreach ($balances as $balance) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }

            $take = Decimal::compare($balance->quantity, $remaining, 3) >= 0
                ? $remaining
                : Decimal::normalize($balance->quantity, 3);
            $allocations[] = [
                'product_batch_id' => $balance->product_batch_id,
                'quantity' => $take,
            ];
            $remaining = Decimal::subtract($remaining, $take, 3);
        }

        if (Decimal::isPositive($remaining)) {
            throw new DomainException('موجودی سری ساخت قابل برداشت برای این کالا کافی نیست.');
        }

        return $allocations;
    }

    private function productBatch(Product $product, int $batchId): ProductBatch
    {
        $batch = ProductBatch::query()
            ->whereKey($batchId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            throw new DomainException('سری ساخت انتخاب‌شده متعلق به این کالا نیست.');
        }

        return $batch;
    }

    private function syntheticBatch(
        Product $product,
        InventoryMovementType $movementType,
        string $date,
    ): ProductBatch {
        if (! in_array($movementType, [
            InventoryMovementType::Opening,
            InventoryMovementType::Adjustment,
        ], true)) {
            throw new DomainException('برای ورود این کالا سری ساخت مشخص را انتخاب کنید.');
        }

        $batchNumber = 'AUTO-'.$date.'-'.$product->id;

        return ProductBatch::query()->firstOrCreate(
            [
                'product_id' => $product->id,
                'batch_number' => $batchNumber,
            ],
            [
                'production_date' => null,
                'expiry_date' => null,
            ],
        );
    }

    private function assertWholeSerialQuantity(string $quantity): void
    {
        if (Decimal::compare($quantity, (string) round((float) $quantity), 3) !== 0) {
            throw new DomainException('کالای سریال‌دار فقط با مقدار صحیح قابل جابه‌جایی است.');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private function calculateInbound(
        InventoryBalance $balance,
        string $quantity,
        string|int|float|null $unitCost,
        string|int|float|null $exactTotalCost,
    ): array {
        $normalizedUnitCost = Decimal::normalize($unitCost ?? 0, Decimal::UNIT_COST_SCALE);
        $totalCost = $exactTotalCost === null
            ? Decimal::multiply($quantity, $normalizedUnitCost, Decimal::MONEY_SCALE)
            : Decimal::normalize($exactTotalCost, Decimal::MONEY_SCALE);

        if (! Decimal::isPositive($totalCost)) {
            throw new DomainException('بهای ورود انبار باید بزرگ‌تر از صفر باشد.');
        }

        $effectiveUnitCost = Decimal::divide($totalCost, $quantity, Decimal::UNIT_COST_SCALE);
        $quantityAfter = Decimal::add($balance->quantity, $quantity, Decimal::QUANTITY_SCALE);
        $valueAfter = Decimal::add($balance->inventory_value, $totalCost, Decimal::MONEY_SCALE);
        $averageCostAfter = Decimal::divide($valueAfter, $quantityAfter, Decimal::UNIT_COST_SCALE);

        return [
            $quantity,
            $effectiveUnitCost,
            $totalCost,
            $quantityAfter,
            $averageCostAfter,
            $valueAfter,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private function calculateOutbound(InventoryBalance $balance, string $quantity): array
    {
        if (! $balance->isAvailable($quantity)) {
            if (Decimal::compare($balance->quantity, '0', Decimal::QUANTITY_SCALE) <= 0) {
                throw new DomainException('کالا در انبار موجود نیست.');
            }

            throw new DomainException(
                'موجودی کافی نیست؛ موجودی قابل استفاده: '.Decimal::normalize($balance->quantity, Decimal::QUANTITY_SCALE).'.',
            );
        }

        $quantityAfter = Decimal::subtract(
            $balance->quantity,
            $quantity,
            Decimal::QUANTITY_SCALE,
        );

        $totalCost = Decimal::isZero($quantityAfter)
            ? Decimal::normalize($balance->inventory_value, Decimal::MONEY_SCALE)
            : Decimal::multiply(
                $balance->average_cost,
                $quantity,
                Decimal::MONEY_SCALE,
            );

        if (Decimal::compare($totalCost, $balance->inventory_value, Decimal::MONEY_SCALE) > 0) {
            throw new DomainException('بهای خروج از ارزش موجودی بیشتر است.');
        }

        $effectiveUnitCost = Decimal::divide($totalCost, $quantity, Decimal::UNIT_COST_SCALE);
        $valueAfter = Decimal::subtract(
            $balance->inventory_value,
            $totalCost,
            Decimal::MONEY_SCALE,
        );

        if (Decimal::isZero($quantityAfter)) {
            $valueAfter = '0.00';
            $averageCostAfter = '0.0000';
        } else {
            $averageCostAfter = Decimal::divide($valueAfter, $quantityAfter, Decimal::UNIT_COST_SCALE);
        }

        return [
            $quantity,
            $effectiveUnitCost,
            $totalCost,
            $quantityAfter,
            $averageCostAfter,
            $valueAfter,
        ];
    }

    /**
     * @param  array<int, string>  $serialNumbers
     * @param  array<int, int>  $serialIds
     * @param  array<int, array{product_batch_id: int, quantity: string|int|float, unit_cost?: string|int|float, total_cost?: string|int|float}>  $batchAllocations
     * @return array<string, mixed>
     */
    private function movementRequestPayload(
        int $warehouseId,
        int $productId,
        InventoryDirection $direction,
        InventoryMovementType $movementType,
        string $quantity,
        string $movementDate,
        ?Model $source,
        ?int $createdBy,
        ?string $reference,
        ?string $description,
        string|int|float|null $unitCost,
        string|int|float|null $exactTotalCost,
        ?int $productBatchId,
        array $serialNumbers,
        array $serialIds,
        array $batchAllocations,
    ): array {
        $serialNumbers = array_map(
            fn (mixed $serial): string => trim((string) $serial),
            $serialNumbers,
        );
        sort($serialNumbers, \SORT_STRING);

        $serialIds = array_map('intval', $serialIds);
        sort($serialIds, \SORT_NUMERIC);

        $batchAllocations = array_map(fn (array $allocation): array => [
            'product_batch_id' => (int) $allocation['product_batch_id'],
            'quantity' => Decimal::normalize($allocation['quantity'], Decimal::QUANTITY_SCALE),
            'unit_cost' => isset($allocation['unit_cost'])
                ? Decimal::normalize($allocation['unit_cost'], Decimal::UNIT_COST_SCALE)
                : null,
            'total_cost' => isset($allocation['total_cost'])
                ? Decimal::normalize($allocation['total_cost'], Decimal::MONEY_SCALE)
                : null,
        ], $batchAllocations);
        usort(
            $batchAllocations,
            fn (array $left, array $right): int => $left['product_batch_id'] <=> $right['product_batch_id'],
        );

        $hasExactTotal = $exactTotalCost !== null;

        return [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'direction' => $direction->value,
            'movement_type' => $movementType->value,
            'quantity' => $quantity,
            'movement_date' => $movementDate,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey() === null ? null : (int) $source->getKey(),
            'created_by' => $createdBy,
            'reference' => $reference,
            'description' => $description,
            'unit_cost' => $direction === InventoryDirection::In && ! $hasExactTotal
                ? Decimal::normalize($unitCost ?? 0, Decimal::UNIT_COST_SCALE)
                : null,
            'exact_total_cost' => $hasExactTotal
                ? Decimal::normalize($exactTotalCost, Decimal::MONEY_SCALE)
                : null,
            'product_batch_id' => $productBatchId,
            'serial_numbers' => $serialNumbers,
            'serial_ids' => $serialIds,
            'batch_allocations' => $batchAllocations,
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    private function assertIdempotentMatch(
        InventoryMovement $movement,
        string $requestHash,
        array $requestPayload,
    ): void {
        if ($movement->idempotency_request_hash !== null) {
            if (hash_equals($movement->idempotency_request_hash, $requestHash)) {
                return;
            }

            throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
        }

        if (! $this->movementMatchesPersistedPayload($movement, $requestPayload)) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function movementMatchesPersistedPayload(InventoryMovement $movement, array $payload): bool
    {
        $matches = (int) $movement->warehouse_id === $payload['warehouse_id']
            && (int) $movement->product_id === $payload['product_id']
            && $movement->direction->value === $payload['direction']
            && $movement->movement_type->value === $payload['movement_type']
            && $movement->movement_date->toDateString() === $payload['movement_date']
            && Decimal::compare($movement->quantity, $payload['quantity'], Decimal::QUANTITY_SCALE) === 0
            && $movement->source_type === $payload['source_type']
            && ($movement->source_id === null ? null : (int) $movement->source_id) === $payload['source_id']
            && ($movement->created_by === null ? null : (int) $movement->created_by) === $payload['created_by']
            && $movement->reference === $payload['reference']
            && $movement->description === $payload['description'];

        if ($payload['direction'] === InventoryDirection::In->value) {
            $matches = $matches && ($payload['exact_total_cost'] !== null
                ? Decimal::compare($movement->total_cost, $payload['exact_total_cost'], Decimal::MONEY_SCALE) === 0
                : Decimal::compare($movement->unit_cost, $payload['unit_cost'], Decimal::UNIT_COST_SCALE) === 0);
        }

        if ($payload['serial_numbers'] !== []) {
            $storedSerialNumbers = $movement->serialAssignments()
                ->with('serial:id,serial_number')
                ->get()
                ->pluck('serial.serial_number')
                ->map(fn (mixed $serial): string => (string) $serial)
                ->sort()
                ->values()
                ->all();
            $matches = $matches && $payload['serial_numbers'] === $storedSerialNumbers;
        }

        if ($payload['serial_ids'] !== []) {
            $storedSerialIds = $movement->serialAssignments()
                ->pluck('product_serial_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $matches = $matches && $payload['serial_ids'] === $storedSerialIds;
        }

        if ($payload['product_batch_id'] !== null) {
            $matches = $matches && $movement->batchAssignments()
                ->where('product_batch_id', $payload['product_batch_id'])
                ->exists();
        }

        if ($payload['batch_allocations'] !== []) {
            $storedAllocations = $movement->batchAssignments()
                ->get(['product_batch_id', 'quantity', 'unit_cost', 'total_cost'])
                ->keyBy(fn (InventoryMovementBatch $allocation): int => (int) $allocation->product_batch_id);
            $matches = $matches && count($payload['batch_allocations']) === $storedAllocations->count();

            foreach ($payload['batch_allocations'] as $allocation) {
                $stored = $storedAllocations->get($allocation['product_batch_id']);

                if (! $stored
                    || Decimal::compare($stored->quantity, $allocation['quantity'], Decimal::QUANTITY_SCALE) !== 0
                    || ($allocation['unit_cost'] !== null
                        && Decimal::compare($stored->unit_cost, $allocation['unit_cost'], Decimal::UNIT_COST_SCALE) !== 0)
                    || ($allocation['total_cost'] !== null
                        && Decimal::compare($stored->total_cost, $allocation['total_cost'], Decimal::MONEY_SCALE) !== 0)) {
                    $matches = false;
                    break;
                }
            }
        }

        return $matches;
    }
}
