<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryService
{
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
        ));
    }

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
        ));
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
            );

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
            );

            return ['out' => $out, 'in' => $in];
        });
    }

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
    ): InventoryMovement {
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

        if (! $product->is_active) {
            throw new DomainException('کالای انتخاب‌شده غیرفعال است.');
        }

        if ($product->variants()->exists()) {
            throw new DomainException('کالای والد تنوع موجودی مستقل ندارد؛ یکی از تنوع‌ها را انتخاب کنید.');
        }

        $normalizedQuantity = Decimal::normalize($quantity, Decimal::QUANTITY_SCALE);
        $normalizedMovementDate = CarbonImmutable::parse($movementDate)->toDateString();

        if (! Decimal::isPositive($normalizedQuantity)) {
            throw new DomainException('مقدار حرکت انبار باید بزرگ‌تر از صفر باشد.');
        }

        $existing = InventoryMovement::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $this->assertIdempotentMatch(
                $existing,
                $warehouse,
                $product,
                $direction,
                $movementType,
                $normalizedQuantity,
            );

            return $existing;
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

        return InventoryMovement::query()->create([
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
            'created_by' => $createdBy,
            'reference' => $reference,
            'description' => $description,
        ]);
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
            throw new DomainException('موجودی کافی نیست؛ موجودی منفی مجاز نیست.');
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

    private function assertIdempotentMatch(
        InventoryMovement $movement,
        Warehouse $warehouse,
        Product $product,
        InventoryDirection $direction,
        InventoryMovementType $movementType,
        string $quantity,
    ): void {
        $matches = (int) $movement->store_id === (int) $warehouse->store_id
            && (int) $movement->branch_id === (int) $warehouse->branch_id
            && (int) $movement->warehouse_id === (int) $warehouse->id
            && (int) $movement->product_id === (int) $product->id
            && $movement->direction === $direction
            && $movement->movement_type === $movementType
            && Decimal::compare($movement->quantity, $quantity, Decimal::QUANTITY_SCALE) === 0;

        if (! $matches) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای حرکت متفاوتی استفاده شده است.');
        }
    }
}
