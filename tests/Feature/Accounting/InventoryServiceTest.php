<?php

use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Database\Seeders\StoreSeeder;

function createInventoryContext(): array
{
    app(StoreSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::query()->create([
        'code' => 'INV-P-001',
        'name' => 'کالای انبار',
        'purchase_price' => 100,
        'sale_price' => 150,
    ]);

    return compact('store', 'branch', 'warehouse', 'product');
}

it('calculates moving weighted average on inbound and outbound movements', function () {
    ['warehouse' => $warehouse, 'product' => $product] = createInventoryContext();
    $service = app(InventoryService::class);

    $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 100,
        movementDate: '2026-10-22',
        idempotencyKey: 'opening-001',
        movementType: InventoryMovementType::Opening,
    );

    $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 200,
        movementDate: '2026-10-23',
        idempotencyKey: 'purchase-001',
    );

    $issue = $service->issue(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        movementDate: '2026-10-24',
        idempotencyKey: 'sale-001',
    );

    $balance = InventoryBalance::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect($issue->unit_cost)->toBe('150.0000')
        ->and($issue->total_cost)->toBe('750.00')
        ->and($issue->quantity_after)->toBe('15.000')
        ->and($issue->average_cost_after)->toBe('150.0000')
        ->and($issue->value_after)->toBe('2250.00')
        ->and($balance->quantity)->toBe('15.000')
        ->and($balance->average_cost)->toBe('150.0000')
        ->and($balance->inventory_value)->toBe('2250.00');
});

it('prevents stock from becoming negative', function () {
    ['warehouse' => $warehouse, 'product' => $product] = createInventoryContext();
    $service = app(InventoryService::class);

    $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 2,
        unitCost: 50,
        movementDate: '2026-10-22',
        idempotencyKey: 'opening-negative-001',
        movementType: InventoryMovementType::Opening,
    );

    expect(fn () => $service->issue(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 3,
        movementDate: '2026-10-23',
        idempotencyKey: 'sale-negative-001',
    ))->toThrow(DomainException::class);

    $balance = InventoryBalance::query()->firstOrFail();

    expect($balance->quantity)->toBe('2.000')
        ->and($balance->inventory_value)->toBe('100.00')
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('returns the existing movement when an idempotency key is repeated', function () {
    ['warehouse' => $warehouse, 'product' => $product] = createInventoryContext();
    $service = app(InventoryService::class);

    $first = $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        unitCost: 100,
        movementDate: '2026-10-22',
        idempotencyKey: 'idempotent-purchase-001',
    );

    $second = $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        unitCost: 100,
        movementDate: '2026-10-22',
        idempotencyKey: 'idempotent-purchase-001',
    );

    expect($second->is($first))->toBeTrue()
        ->and(InventoryMovement::query()->count())->toBe(1)
        ->and(InventoryBalance::query()->firstOrFail()->quantity)->toBe('5.000');
});

it('transfers inventory at the source average cost without changing total value', function () {
    ['store' => $store, 'branch' => $branch, 'warehouse' => $source, 'product' => $product] = createInventoryContext();
    $destination = Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'code' => 'SECONDARY',
        'name' => 'انبار دوم',
    ]);
    $service = app(InventoryService::class);

    $service->receive(
        warehouseId: $source->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 100,
        movementDate: '2026-10-22',
        idempotencyKey: 'transfer-opening-001',
        movementType: InventoryMovementType::Opening,
    );

    $movements = $service->transfer(
        sourceWarehouseId: $source->id,
        destinationWarehouseId: $destination->id,
        productId: $product->id,
        quantity: 4,
        movementDate: '2026-10-23',
        idempotencyKey: 'transfer-001',
    );

    $sourceBalance = InventoryBalance::query()->where('warehouse_id', $source->id)->firstOrFail();
    $destinationBalance = InventoryBalance::query()->where('warehouse_id', $destination->id)->firstOrFail();

    expect($movements['out']->total_cost)->toBe('400.00')
        ->and($movements['in']->total_cost)->toBe('400.00')
        ->and($sourceBalance->quantity)->toBe('6.000')
        ->and($sourceBalance->inventory_value)->toBe('600.00')
        ->and($destinationBalance->quantity)->toBe('4.000')
        ->and($destinationBalance->average_cost)->toBe('100.0000')
        ->and($destinationBalance->inventory_value)->toBe('400.00');
});

it('rejects a warehouse whose branch belongs to another store', function () {
    ['store' => $store] = createInventoryContext();
    $otherStore = Store::query()->create([
        'code' => 'OTHER',
        'name' => 'فروشگاه دیگر',
    ]);
    $otherBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'OTHER-BR',
        'name' => 'شعبه دیگر',
    ]);

    Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $otherBranch->id,
        'code' => 'INVALID',
        'name' => 'انبار نامعتبر',
    ]);
})->throws(DomainException::class);

it('rejects a backdated movement after later inventory activity', function () {
    ['warehouse' => $warehouse, 'product' => $product] = createInventoryContext();
    $service = app(InventoryService::class);
    $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        unitCost: 100,
        movementDate: '2026-10-20',
        idempotencyKey: 'chronology-first',
        movementType: InventoryMovementType::Opening,
    );

    expect(fn () => $service->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        unitCost: 200,
        movementDate: '2026-10-10',
        idempotencyKey: 'chronology-backdated',
    ))->toThrow(DomainException::class);

    $balance = InventoryBalance::query()->firstOrFail();

    expect($balance->quantity)->toBe('5.000')
        ->and($balance->inventory_value)->toBe('500.00')
        ->and(InventoryMovement::query()->count())->toBe(1);
});
