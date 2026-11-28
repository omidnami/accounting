<?php

use App\Enums\InventoryMovementType;
use App\Models\InventoryBalance;
use App\Models\InventoryBatchBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use Database\Seeders\StoreSeeder;

/**
 * @return array<string, mixed>
 */
function inventoryAllocationInvariantContext(bool $serials = false): array
{
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_default', true)->firstOrFail();
    $product = Product::query()->create([
        'code' => 'ALLOCATION-PRODUCT',
        'name' => 'کالای تخصیص',
        'unit' => 'عدد',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 0,
        'track_batches' => true,
        'track_serials' => $serials,
        'is_active' => true,
    ]);
    $batches = collect([1, 2, 3])->map(fn (int $number): ProductBatch => ProductBatch::query()->create([
        'product_id' => $product->id,
        'batch_number' => 'ALLOCATION-B-'.$number,
    ]))->all();

    return compact('store', 'branch', 'warehouse', 'product', 'batches');
}

it('allocates inbound batch quantity and rounded cost exactly', function () {
    $context = inventoryAllocationInvariantContext();
    [$first, $second, $third] = $context['batches'];
    $movement = app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 3,
        unitCost: 1,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-inbound-exact',
        movementType: InventoryMovementType::Opening,
        exactTotalCost: '10.00',
        batchAllocations: [
            ['product_batch_id' => $third->id, 'quantity' => 1],
            ['product_batch_id' => $first->id, 'quantity' => 1],
            ['product_batch_id' => $second->id, 'quantity' => 1],
        ],
    );
    $assignments = InventoryMovementBatch::query()
        ->where('inventory_movement_id', $movement->id)
        ->orderBy('product_batch_id')
        ->get();

    expect($assignments->pluck('total_cost')->all())->toBe(['3.33', '3.34', '3.33'])
        ->and($assignments->reduce(fn (string $carry, InventoryMovementBatch $row): string => Decimal::add($carry, $row->total_cost, 2), '0.00'))->toBe('10.00')
        ->and($assignments->reduce(fn (string $carry, InventoryMovementBatch $row): string => Decimal::add($carry, $row->quantity, 3), '0.000'))->toBe('3.000')
        ->and(InventoryBatchBalance::query()->where('warehouse_id', $context['warehouse']->id)->get()->reduce(
            fn (string $carry, InventoryBatchBalance $balance): string => Decimal::add($carry, $balance->quantity, 3),
            '0.000',
        ))->toBe('3.000');
});

it('rejects mismatched inbound batch quantities without partial inventory changes', function () {
    $context = inventoryAllocationInvariantContext();
    $batch = $context['batches'][0];

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-inbound-short',
        movementType: InventoryMovementType::Opening,
        batchAllocations: [
            ['product_batch_id' => $batch->id, 'quantity' => 1],
        ],
    ))->toThrow(DomainException::class, 'جمع مقدار تخصیص‌های سری ساخت');

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryBalance::query()->count())->toBe(0)
        ->and(InventoryBatchBalance::query()->count())->toBe(0)
        ->and(InventoryMovementBatch::query()->count())->toBe(0);
});

it('rejects explicit batch costs that do not match the movement cost', function () {
    $context = inventoryAllocationInvariantContext();
    [$first, $second] = $context['batches'];

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        unitCost: 5,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-inbound-cost',
        movementType: InventoryMovementType::Opening,
        exactTotalCost: '10.00',
        batchAllocations: [
            ['product_batch_id' => $first->id, 'quantity' => 1, 'total_cost' => '4.00'],
            ['product_batch_id' => $second->id, 'quantity' => 1, 'total_cost' => '4.00'],
        ],
    ))->toThrow(DomainException::class, 'جمع هزینهٔ تخصیص‌های سری ساخت');

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryMovementBatch::query()->count())->toBe(0);
});

it('allocates outbound batch cost without losing rounding residuals', function () {
    $context = inventoryAllocationInvariantContext();
    [$first, $second, $third] = $context['batches'];
    $inventory = app(InventoryService::class);
    $inventory->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 3,
        unitCost: 1,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-outbound-opening',
        movementType: InventoryMovementType::Opening,
        exactTotalCost: '10.00',
        batchAllocations: [
            ['product_batch_id' => $first->id, 'quantity' => 1],
            ['product_batch_id' => $second->id, 'quantity' => 1],
            ['product_batch_id' => $third->id, 'quantity' => 1],
        ],
    );
    $movement = $inventory->issue(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-outbound-issue',
        batchAllocations: [
            ['product_batch_id' => $second->id, 'quantity' => 1],
            ['product_batch_id' => $first->id, 'quantity' => 1],
        ],
    );
    $assignments = InventoryMovementBatch::query()
        ->where('inventory_movement_id', $movement->id)
        ->orderBy('product_batch_id')
        ->get();

    expect($movement->total_cost)->toBe('6.67')
        ->and($assignments->pluck('total_cost')->all())->toBe(['3.34', '3.33'])
        ->and($assignments->reduce(fn (string $carry, InventoryMovementBatch $row): string => Decimal::add($carry, $row->total_cost, 2), '0.00'))->toBe('6.67')
        ->and(InventoryBatchBalance::query()->where('warehouse_id', $context['warehouse']->id)->get()->reduce(
            fn (string $carry, InventoryBatchBalance $balance): string => Decimal::add($carry, $balance->quantity, 3),
            '0.000',
        ))->toBe('1.000');
});

it('rejects duplicate batch allocations and allocations for non tracked products', function () {
    $context = inventoryAllocationInvariantContext();
    $batch = $context['batches'][0];

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-duplicate-batch',
        movementType: InventoryMovementType::Opening,
        batchAllocations: [
            ['product_batch_id' => $batch->id, 'quantity' => 1],
            ['product_batch_id' => $batch->id, 'quantity' => 1],
        ],
    ))->toThrow(DomainException::class, 'شناسهٔ معتبر و یکتا');

    $product = Product::query()->create([
        'code' => 'ALLOCATION-PLAIN',
        'name' => 'کالای بدون ردیابی',
        'unit' => 'عدد',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 0,
        'is_active' => true,
    ]);

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $product->id,
        quantity: 1,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-non-tracked',
        movementType: InventoryMovementType::Opening,
        batchAllocations: [
            ['product_batch_id' => $batch->id, 'quantity' => 1],
        ],
    ))->toThrow(DomainException::class, 'کالای بدون ردیابی سری ساخت');
});

it('reports zero stock explicitly and shows available stock for shortages', function () {
    $context = inventoryAllocationInvariantContext();
    $inventory = app(InventoryService::class);

    expect(fn () => $inventory->issue(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 1,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-zero-stock',
    ))->toThrow(DomainException::class, 'کالا در انبار موجود نیست');

    $inventory->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 1,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-shortage-opening',
        movementType: InventoryMovementType::Opening,
        productBatchId: $context['batches'][0]->id,
    );

    expect(fn () => $inventory->issue(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-shortage-issue',
    ))->toThrow(DomainException::class, 'موجودی قابل استفاده: 1.000');
});

it('rejects duplicate serial ids before creating inventory tracking rows', function () {
    $context = inventoryAllocationInvariantContext(true);
    $batch = $context['batches'][0];
    $serial = ProductSerial::query()->create([
        'product_id' => $context['product']->id,
        'product_batch_id' => $batch->id,
        'warehouse_id' => $context['warehouse']->id,
        'serial_number' => 'ALLOCATION-SERIAL-1',
        'status' => 'available',
    ]);

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'allocation-duplicate-serial',
        movementType: InventoryMovementType::SalesReturn,
        productBatchId: $batch->id,
        serialIds: [$serial->id, $serial->id],
    ))->toThrow(DomainException::class, 'سریال‌های ورودی تکراری');

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryMovementBatch::query()->count())->toBe(0);
});
