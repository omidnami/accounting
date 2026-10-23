<?php

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCardexReportService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\StoreSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function inventoryCardexUser(UserRole $role): User
{
    app(StoreSeeder::class)->run();
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

/**
 * @return array{store: Store, warehouse: Warehouse, product: Product}
 */
function inventoryCardexContext(): array
{
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $warehouse = $store->defaultBranch->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::query()->create([
        'code' => 'CARDEX-001',
        'name' => 'کالای کاردکس',
        'unit' => 'عدد',
        'purchase_price' => 100,
        'sale_price' => 150,
    ]);

    return compact('store', 'warehouse', 'product');
}

function inventoryCardexSeedMovements(Warehouse $warehouse, Product $product): void
{
    $inventory = app(InventoryService::class);
    $inventory->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 100,
        movementDate: '2026-09-01',
        idempotencyKey: 'cardex-opening',
        movementType: InventoryMovementType::Opening,
    );
    $inventory->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 200,
        movementDate: '2026-10-02',
        idempotencyKey: 'cardex-purchase',
    );
    $inventory->issue(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        movementDate: '2026-10-05',
        idempotencyKey: 'cardex-sale',
    );
    $inventory->issue(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 1,
        movementDate: '2026-11-01',
        idempotencyKey: 'cardex-after-range',
    );
}

it('builds a historical cardex from movement snapshots', function () {
    ['store' => $store, 'warehouse' => $warehouse, 'product' => $product] = inventoryCardexContext();
    inventoryCardexSeedMovements($warehouse, $product);

    $report = app(InventoryCardexReportService::class)->report(
        storeId: $store->id,
        productId: $product->id,
        warehouseId: $warehouse->id,
        from: '2026-10-01',
        to: '2026-10-31',
        includeFinancials: true,
    );
    $rows = $report['movements']->items();

    expect($report['summary'])->toMatchArray([
        'opening_quantity' => '10.000',
        'closing_quantity' => '15.000',
        'inbound_quantity' => '10.000',
        'outbound_quantity' => '5.000',
        'net_quantity' => '5.000',
        'movement_count' => 2,
        'opening_value' => '1000.00',
        'closing_value' => '2250.00',
        'inbound_value' => '2000.00',
        'outbound_value' => '750.00',
    ])
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['movement_type'])->toBe('purchase')
        ->and($rows[0]['quantity_after'])->toBe('20.000')
        ->and($rows[0]['value_after'])->toBe('3000.00')
        ->and($rows[1]['movement_type'])->toBe('sale')
        ->and($rows[1]['total_cost'])->toBe('750.00')
        ->and($rows[1]['quantity_after'])->toBe('15.000')
        ->and($rows[1]['value_after'])->toBe('2250.00');
});

it('filters cardex rows without changing historical balances', function () {
    ['store' => $store, 'warehouse' => $warehouse, 'product' => $product] = inventoryCardexContext();
    inventoryCardexSeedMovements($warehouse, $product);

    $report = app(InventoryCardexReportService::class)->report(
        storeId: $store->id,
        productId: $product->id,
        warehouseId: $warehouse->id,
        from: '2026-10-01',
        to: '2026-10-31',
        movementType: InventoryMovementType::Sale,
        direction: InventoryDirection::Out,
    );

    expect($report['summary'])
        ->toMatchArray([
            'opening_quantity' => '10.000',
            'closing_quantity' => '15.000',
            'inbound_quantity' => '0.000',
            'outbound_quantity' => '5.000',
            'movement_count' => 1,
        ])
        ->not->toHaveKey('closing_value')
        ->and($report['movements']->items())->toHaveCount(1)
        ->and($report['movements']->items()[0]['movement_type'])->toBe('sale')
        ->and($report['movements']->items()[0])->not->toHaveKey('total_cost');
});

it('rejects a cardex warehouse from another store', function () {
    ['store' => $store, 'product' => $product] = inventoryCardexContext();
    $otherStore = Store::query()->create([
        'code' => 'CARDEX-OTHER',
        'name' => 'فروشگاه دیگر',
    ]);
    $otherBranch = $otherStore->branches()->create([
        'code' => 'CARDEX-OTHER-BR',
        'name' => 'شعبه دیگر',
    ]);
    $otherWarehouse = Warehouse::query()->create([
        'store_id' => $otherStore->id,
        'branch_id' => $otherBranch->id,
        'code' => 'CARDEX-OTHER-WH',
        'name' => 'انبار دیگر',
    ]);

    expect(fn () => app(InventoryCardexReportService::class)->report(
        storeId: $store->id,
        productId: $product->id,
        warehouseId: $otherWarehouse->id,
        from: '2026-10-01',
        to: '2026-10-31',
    ))->toThrow(InvalidArgumentException::class);
});

it('redirects guests and forbids roles without inventory reporting access', function (UserRole $role) {
    $this->get(route('inventory.cardex'))->assertRedirect(route('login'));

    $user = inventoryCardexUser($role);

    $this->actingAs($user)
        ->get(route('inventory.cardex'))
        ->assertForbidden();
})->with([
    'cashier' => [UserRole::Cashier],
    'viewer' => [UserRole::Viewer],
]);

it('allows inventory reporting roles and limits financial data for warehouse users', function (UserRole $role) {
    ['store' => $store, 'warehouse' => $warehouse, 'product' => $product] = inventoryCardexContext();
    inventoryCardexSeedMovements($warehouse, $product);
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    $this->actingAs($user)
        ->get(route('inventory.cardex', [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/cardex/Index')
            ->where('filters.product_id', $product->id)
            ->where('filters.warehouse_id', $warehouse->id)
            ->where('report.product.id', $product->id)
            ->where('report.warehouse.id', $warehouse->id)
            ->where('report.summary.closing_quantity', '15.000')
            ->has('report.movements.data', 2)
            ->where('can_view_costs', $role !== UserRole::Warehouse)
            ->where('report.include_financials', $role !== UserRole::Warehouse)
            ->when(
                $role === UserRole::Warehouse,
                fn (Assert $page) => $page
                    ->missing('report.summary.closing_value')
                    ->missing('report.movements.data.0.total_cost'),
                fn (Assert $page) => $page
                    ->where('report.summary.closing_value', '2250.00')
                    ->where('report.movements.data.0.total_cost', '2000.00'),
            ));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
    'warehouse' => [UserRole::Warehouse],
]);

it('validates cardex dates and warehouse scope through the interface', function () {
    ['warehouse' => $warehouse, 'product' => $product] = inventoryCardexContext();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    $otherStore = Store::query()->create([
        'code' => 'CARDEX-UI-OTHER',
        'name' => 'فروشگاه دیگر',
    ]);
    $otherBranch = $otherStore->branches()->create([
        'code' => 'CARDEX-UI-OTHER-BR',
        'name' => 'شعبه دیگر',
    ]);
    $otherWarehouse = Warehouse::query()->create([
        'store_id' => $otherStore->id,
        'branch_id' => $otherBranch->id,
        'code' => 'CARDEX-UI-OTHER-WH',
        'name' => 'انبار دیگر',
    ]);

    $this->actingAs($manager)
        ->get(route('inventory.cardex', [
            'from' => '2026-10-31',
            'to' => '2026-10-01',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
        ]))
        ->assertSessionHasErrors('to');

    $this->actingAs($manager)
        ->get(route('inventory.cardex', [
            'from' => '2030-01-01',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
        ]))
        ->assertSessionHasErrors('to');

    $this->actingAs($manager)
        ->get(route('inventory.cardex', [
            'to' => '2000-01-01',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
        ]))
        ->assertSessionHasErrors('to');

    $this->actingAs($manager)
        ->get(route('inventory.cardex', [
            'product_id' => $product->id,
            'warehouse_id' => $otherWarehouse->id,
        ]))
        ->assertSessionHasErrors('warehouse_id');

    $this->actingAs($manager)
        ->get(route('inventory.cardex'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/cardex/Index')
            ->where('report', null)
            ->where('filters.product_id', null)
            ->where('filters.warehouse_id', null));
});

it('rejects an incompatible cardex movement type and direction', function () {
    ['warehouse' => $warehouse, 'product' => $product] = inventoryCardexContext();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();

    $this->actingAs($manager)
        ->get(route('inventory.cardex', [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => InventoryMovementType::Sale->value,
            'direction' => InventoryDirection::In->value,
        ]))
        ->assertSessionHasErrors('movement_type');

    $this->actingAs($manager)
        ->get(route('inventory.cardex', [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => InventoryMovementType::Sale->value,
            'direction' => InventoryDirection::Out->value,
        ]))
        ->assertOk();
});
