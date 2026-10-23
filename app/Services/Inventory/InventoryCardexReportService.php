<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class InventoryCardexReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(
        int $storeId,
        int $productId,
        int $warehouseId,
        string $from,
        string $to,
        ?InventoryMovementType $movementType = null,
        ?InventoryDirection $direction = null,
        bool $includeFinancials = false,
        int $perPage = 50,
        int $page = 1,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('store_id', $storeId)
            ->first();
        $product = Product::query()->find($productId);

        if (! $warehouse) {
            throw new InvalidArgumentException('انبار انتخاب‌شده متعلق به این فروشگاه نیست.');
        }

        if (! $product) {
            throw new InvalidArgumentException('کالای انتخاب‌شده معتبر نیست.');
        }

        $opening = $this->snapshot(
            $storeId,
            $productId,
            $warehouseId,
            $range->from,
            strict: true,
        );
        $closing = $this->snapshot(
            $storeId,
            $productId,
            $warehouseId,
            $range->to,
            strict: false,
        );
        $aggregates = (array) $this->movementsQuery(
            $storeId,
            $productId,
            $warehouseId,
            $range->from,
            $range->to,
            $movementType,
            $direction,
        )
            ->toBase()
            ->selectRaw("
                COUNT(*) AS movement_count,
                COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE 0 END), 0) AS inbound_quantity,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN quantity ELSE 0 END), 0) AS outbound_quantity,
                COALESCE(SUM(CASE WHEN direction = 'in' THEN total_cost ELSE 0 END), 0) AS inbound_value,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN total_cost ELSE 0 END), 0) AS outbound_value
            ")
            ->first();
        $movements = $this->movementsQuery(
            $storeId,
            $productId,
            $warehouseId,
            $range->from,
            $range->to,
            $movementType,
            $direction,
        )
            ->orderBy('movement_date')
            ->orderBy('id')
            ->paginate(max(1, min($perPage, 100)), ['*'], 'page', max(1, $page))
            ->withQueryString()
            ->through(
                fn (InventoryMovement $movement): array => $this->movementRow(
                    $movement,
                    $includeFinancials,
                ),
            );
        $summary = [
            'opening_quantity' => $this->quantity($opening?->quantity_after),
            'closing_quantity' => $this->quantity($closing?->quantity_after),
            'inbound_quantity' => $this->quantity($aggregates['inbound_quantity'] ?? 0),
            'outbound_quantity' => $this->quantity($aggregates['outbound_quantity'] ?? 0),
            'net_quantity' => Decimal::subtract(
                $aggregates['inbound_quantity'] ?? 0,
                $aggregates['outbound_quantity'] ?? 0,
                Decimal::QUANTITY_SCALE,
            ),
            'movement_count' => (int) ($aggregates['movement_count'] ?? 0),
        ];

        if ($includeFinancials) {
            $summary += [
                'opening_value' => $this->money($opening?->value_after),
                'closing_value' => $this->money($closing?->value_after),
                'opening_average_cost' => $this->unitCost($opening?->average_cost_after),
                'closing_average_cost' => $this->unitCost($closing?->average_cost_after),
                'inbound_value' => $this->money($aggregates['inbound_value'] ?? 0),
                'outbound_value' => $this->money($aggregates['outbound_value'] ?? 0),
            ];
        }

        return [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $product->unit,
            ],
            'warehouse' => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
            ],
            'summary' => $summary,
            'movements' => $movements,
            'include_financials' => $includeFinancials,
        ];
    }

    /**
     * @return Builder<InventoryMovement>
     */
    private function movementsQuery(
        int $storeId,
        int $productId,
        int $warehouseId,
        string $from,
        string $to,
        ?InventoryMovementType $movementType,
        ?InventoryDirection $direction,
    ): Builder {
        return InventoryMovement::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereBetween('movement_date', [$from, $to])
            ->when(
                $movementType !== null,
                fn (Builder $query) => $query->where('movement_type', $movementType),
            )
            ->when(
                $direction !== null,
                fn (Builder $query) => $query->where('direction', $direction),
            );
    }

    private function snapshot(
        int $storeId,
        int $productId,
        int $warehouseId,
        string $date,
        bool $strict,
    ): ?InventoryMovement {
        return InventoryMovement::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('movement_date', $strict ? '<' : '<=', $date)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(
        InventoryMovement $movement,
        bool $includeFinancials,
    ): array {
        $row = [
            'id' => $movement->id,
            'movement_date' => $movement->movement_date->toDateString(),
            'direction' => $movement->direction->value,
            'movement_type' => $movement->movement_type->value,
            'quantity' => $this->quantity($movement->quantity),
            'quantity_after' => $this->quantity($movement->quantity_after),
            'reference' => $movement->reference,
            'description' => $movement->description,
        ];

        if ($includeFinancials) {
            $row += [
                'unit_cost' => $this->unitCost($movement->unit_cost),
                'total_cost' => $this->money($movement->total_cost),
                'average_cost_after' => $this->unitCost($movement->average_cost_after),
                'value_after' => $this->money($movement->value_after),
            ];
        }

        return $row;
    }

    private function quantity(string|int|float|null $value): string
    {
        return Decimal::normalize($value ?? 0, Decimal::QUANTITY_SCALE);
    }

    private function unitCost(string|int|float|null $value): string
    {
        return Decimal::normalize($value ?? 0, Decimal::UNIT_COST_SCALE);
    }

    private function money(string|int|float|null $value): string
    {
        return Decimal::normalize($value ?? 0, Decimal::MONEY_SCALE);
    }
}
