<?php

namespace App\Services\Inventory;

use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryTransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AccountingPeriodLockService $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function transfer(Store $store, array $data, User $creator): InventoryTransfer
    {
        $requestHash = $this->requestHash($data);

        try {
            return DB::transaction(function () use ($store, $data, $creator, $requestHash): InventoryTransfer {
                $existing = InventoryTransfer::query()
                    ->where('idempotency_key', (string) $data['submission_token'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->assertIdempotentMatch($existing, $requestHash);

                    return $existing->load(['sourceWarehouse', 'destinationWarehouse', 'product']);
                }

                $source = $this->warehouse($store, (int) $data['source_warehouse_id']);
                $destination = $this->warehouse($store, (int) $data['destination_warehouse_id']);

                if ($source->id === $destination->id) {
                    throw new DomainException('انبار مبدأ و مقصد نباید یکسان باشند.');
                }

                if ($source->branch_id !== $destination->branch_id) {
                    throw new DomainException('انتقال فعلی فقط بین انبارهای یک شعبه مجاز است.');
                }

                $product = Product::query()
                    ->whereKey((int) $data['product_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $product) {
                    throw new DomainException('کالای انتقال باید فعال و معتبر باشد.');
                }

                $quantity = Decimal::normalize($data['quantity'], 3);

                if (! Decimal::isPositive($quantity)) {
                    throw new DomainException('مقدار انتقال باید بزرگ‌تر از صفر باشد.');
                }

                $context = $this->periods->lockCurrentOpenPeriod(
                    $store->id,
                    $source->branch_id,
                    (string) $data['transfer_date'],
                );
                $transfer = InventoryTransfer::query()->create([
                    'store_id' => $store->id,
                    'branch_id' => $source->branch_id,
                    'source_warehouse_id' => $source->id,
                    'destination_warehouse_id' => $destination->id,
                    'product_id' => $product->id,
                    'fiscal_year_id' => $context['fiscal_year']->id,
                    'accounting_period_id' => $context['period']->id,
                    'transfer_number' => 'TRF-'.strtoupper((string) Str::ulid()),
                    'transfer_date' => (string) $data['transfer_date'],
                    'quantity' => $quantity,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'idempotency_key' => (string) $data['submission_token'],
                    'idempotency_request_hash' => $requestHash,
                    'created_by' => $creator->id,
                ]);
                $movements = $this->inventory->transfer(
                    sourceWarehouseId: $source->id,
                    destinationWarehouseId: $destination->id,
                    productId: $product->id,
                    quantity: $quantity,
                    movementDate: $transfer->transfer_date->toDateString(),
                    idempotencyKey: "inventory-transfer:{$transfer->id}",
                    createdBy: $creator->id,
                    reference: $transfer->transfer_number,
                    description: 'انتقال بین انبارها',
                    source: $transfer,
                );
                $transfer->recordCostSnapshot(
                    (string) $movements['out']->unit_cost,
                    (string) $movements['out']->total_cost,
                );

                return $transfer->refresh()->load([
                    'sourceWarehouse',
                    'destinationWarehouse',
                    'product',
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = InventoryTransfer::query()
                ->where('idempotency_key', (string) $data['submission_token'])
                ->first();

            if ($existing) {
                $this->assertIdempotentMatch($existing, $requestHash);

                return $existing->load(['sourceWarehouse', 'destinationWarehouse', 'product']);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(
        Store $store,
        array $filters,
        bool $includeCosts,
    ): LengthAwarePaginator {
        return InventoryTransfer::query()
            ->where('store_id', $store->id)
            ->with([
                'sourceWarehouse:id,code,name',
                'destinationWarehouse:id,code,name',
                'product:id,code,name,unit',
                'creator:id,name',
            ])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where(fn ($query) => $query
                    ->where('source_warehouse_id', $warehouseId)
                    ->orWhere('destination_warehouse_id', $warehouseId)))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (InventoryTransfer $transfer) use ($includeCosts): array {
                $row = [
                    'id' => $transfer->id,
                    'transfer_number' => $transfer->transfer_number,
                    'transfer_date' => $transfer->transfer_date->toDateString(),
                    'quantity' => Decimal::normalize($transfer->quantity, 3),
                    'reference' => $transfer->reference,
                    'notes' => $transfer->notes,
                    'source_warehouse' => $transfer->sourceWarehouse->only(['id', 'code', 'name']),
                    'destination_warehouse' => $transfer->destinationWarehouse->only(['id', 'code', 'name']),
                    'product' => $transfer->product->only(['id', 'code', 'name', 'unit']),
                    'creator' => $transfer->creator?->name,
                ];

                if ($includeCosts) {
                    $row['unit_cost'] = $transfer->unit_cost === null
                        ? null
                        : Decimal::normalize($transfer->unit_cost, 4);
                    $row['total_cost'] = $transfer->total_cost === null
                        ? null
                        : Decimal::normalize($transfer->total_cost, 2);
                }

                return $row;
            });
    }

    private function warehouse(Store $store, int $id): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereKey($id)
            ->where('store_id', $store->id)
            ->active()
            ->first();

        if (! $warehouse) {
            throw new DomainException('انبار انتقال باید فعال و متعلق به فروشگاه باشد.');
        }

        return $warehouse;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requestHash(array $data): string
    {
        return hash('sha256', json_encode([
            'source_warehouse_id' => (int) ($data['source_warehouse_id'] ?? 0),
            'destination_warehouse_id' => (int) ($data['destination_warehouse_id'] ?? 0),
            'product_id' => (int) ($data['product_id'] ?? 0),
            'quantity' => Decimal::normalize($data['quantity'] ?? 0, 3),
            'transfer_date' => (string) ($data['transfer_date'] ?? ''),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertIdempotentMatch(InventoryTransfer $transfer, string $hash): void
    {
        if ($transfer->idempotency_request_hash !== $hash) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای انتقال دیگری استفاده شده است.');
        }
    }
}
