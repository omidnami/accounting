<?php

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Database\Seeders\StoreSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function productManagementUser(UserRole $role): User
{
    app(StoreSeeder::class)->run();
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

function productMainStore(): Store
{
    return Store::query()->where('code', 'MAIN')->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'PROD-1001',
        'barcode' => '626000000001',
        'name' => 'کالای نمونه',
        'unit' => 'عدد',
        'purchase_price' => '100000',
        'sale_price' => '150000',
        'tax_rate' => '10',
        'reorder_level' => '5',
        'description' => 'کالای آزمایشی',
        'is_active' => true,
    ], $overrides);
}

it('redirects guests and forbids unauthorized roles from product management', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));

    $cashier = productManagementUser(UserRole::Cashier);

    $this->actingAs($cashier)
        ->get(route('products.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->post(route('products.store'), productPayload())
        ->assertForbidden();
});

it('allows managers and accountants to manage products', function (UserRole $role) {
    $user = productManagementUser($role);

    $this->actingAs($user)
        ->get(route('products.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/Form')
            ->where('product', null));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('creates a product with barcode, pricing, tax and reorder level', function () {
    $manager = productManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('products.store'), productPayload())
        ->assertRedirect(route('products.index'));

    $product = Product::query()->where('code', 'PROD-1001')->firstOrFail();

    expect($product->barcode)->toBe('626000000001')
        ->and($product->purchase_price)->toBe('100000.00')
        ->and($product->sale_price)->toBe('150000.00')
        ->and($product->tax_rate)->toBe('10.00')
        ->and($product->reorder_level)->toBe('5.000')
        ->and($product->version)->toBe(0);
});

it('normalizes Persian and Arabic numeric input with separators', function () {
    $manager = productManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('products.store'), productPayload([
            'purchase_price' => '۱۰۰٬۰۰۰',
            'sale_price' => '۱۵۰٫۵۰',
            'tax_rate' => '۹',
            'reorder_level' => '٥',
        ]))
        ->assertRedirect(route('products.index'));

    $product = Product::query()->where('code', 'PROD-1001')->firstOrFail();

    expect($product->purchase_price)->toBe('100000.00')
        ->and($product->sale_price)->toBe('150.50')
        ->and($product->tax_rate)->toBe('9.00')
        ->and($product->reorder_level)->toBe('5.000');
});

it('enforces unique product codes and barcodes', function () {
    $manager = productManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('products.store'), productPayload())
        ->assertRedirect(route('products.index'));

    $this->actingAs($manager)
        ->post(route('products.store'), productPayload([
            'barcode' => '626000000002',
        ]))
        ->assertSessionHasErrors('code');

    $this->actingAs($manager)
        ->post(route('products.store'), productPayload([
            'code' => 'PROD-1002',
        ]))
        ->assertSessionHasErrors('barcode');
});

it('normalizes Persian and Arabic digits when searching product barcodes', function (string $search) {
    $manager = productManagementUser(UserRole::Manager);
    Product::query()->create(productPayload([
        'barcode' => '626123456789',
    ]));

    $this->actingAs($manager)
        ->get(route('products.index', ['search' => $search]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.code', 'PROD-1001'));
})->with([
    'Persian digits' => ['۶۲۶۱۲۳۴۵۶۷۸۹'],
    'Arabic digits' => ['٦٢٦١٢٣٤٥٦٧٨٩'],
]);

it('aggregates stock and filters in-stock, low-stock and out-of-stock products', function () {
    $manager = productManagementUser(UserRole::Manager);
    $store = productMainStore();
    $warehouse = $store->defaultBranch->warehouses()->where('is_default', true)->firstOrFail();
    $inventory = app(InventoryService::class);
    $low = Product::query()->create(productPayload([
        'code' => 'LOW-1',
        'barcode' => null,
        'reorder_level' => 5,
    ]));
    $healthy = Product::query()->create(productPayload([
        'code' => 'OK-1',
        'barcode' => null,
        'reorder_level' => 5,
    ]));
    $out = Product::query()->create(productPayload([
        'code' => 'OUT-1',
        'barcode' => null,
        'reorder_level' => 2,
    ]));

    $inventory->receive(
        warehouseId: $warehouse->id,
        productId: $low->id,
        quantity: 3,
        unitCost: 100,
        movementDate: '2026-10-22',
        idempotencyKey: 'catalog-low-stock',
        movementType: InventoryMovementType::Opening,
    );
    $inventory->receive(
        warehouseId: $warehouse->id,
        productId: $healthy->id,
        quantity: 10,
        unitCost: 100,
        movementDate: '2026-10-22',
        idempotencyKey: 'catalog-healthy-stock',
        movementType: InventoryMovementType::Opening,
    );

    $this->actingAs($manager)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/Index')
            ->where('summary.total', 3)
            ->where('summary.in_stock', 2)
            ->where('summary.low_stock', 1)
            ->where('summary.out_of_stock', 1)
            ->where('summary.inventory_value', '1300.00'));

    $this->actingAs($manager)
        ->get(route('products.index', ['stock_status' => 'low_stock']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.code', 'LOW-1')
            ->where('products.data.0.stock_status', 'low_stock'));

    $this->actingAs($manager)
        ->get(route('products.index', ['stock_status' => 'out_of_stock']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.code', 'OUT-1'));
});

it('scopes inventory aggregates to the selected store warehouse', function () {
    $manager = productManagementUser(UserRole::Manager);
    $store = productMainStore();
    $firstWarehouse = $store->defaultBranch->warehouses()->where('is_default', true)->firstOrFail();
    $secondWarehouse = Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $store->defaultBranch->id,
        'code' => 'SECOND',
        'name' => 'انبار دوم',
        'is_active' => true,
    ]);
    $product = Product::query()->create(productPayload([
        'barcode' => null,
        'reorder_level' => 0,
    ]));
    $inventory = app(InventoryService::class);

    foreach ([
        [$firstWarehouse, 2, 'warehouse-filter-first'],
        [$secondWarehouse, 7, 'warehouse-filter-second'],
    ] as [$warehouse, $quantity, $key]) {
        $inventory->receive(
            warehouseId: $warehouse->id,
            productId: $product->id,
            quantity: $quantity,
            unitCost: 100,
            movementDate: '2026-10-22',
            idempotencyKey: $key,
            movementType: InventoryMovementType::Opening,
        );
    }
    $secondWarehouse->update(['is_active' => false]);

    $this->actingAs($manager)
        ->get(route('products.index', ['warehouse_id' => $firstWarehouse->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.total_quantity', '2.000')
            ->where('products.data.0.inventory_value', '200.00')
            ->where('summary.inventory_value', '200.00'));

    $this->actingAs($manager)
        ->get(route('products.index', ['warehouse_id' => $secondWarehouse->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.total_quantity', '7.000')
            ->where('products.data.0.inventory_value', '700.00')
            ->where('warehouses', fn ($warehouses) => collect($warehouses)->contains(
                fn ($warehouse) => $warehouse['id'] === $secondWarehouse->id
                    && $warehouse['is_active'] === false,
            )));

    $this->actingAs($manager)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.total_quantity', '9.000')
            ->where('products.data.0.inventory_value', '900.00')
            ->where('summary.inventory_value', '900.00'));
});

it('rejects an invalid warehouse filter and hides products through authorization', function () {
    $manager = productManagementUser(UserRole::Manager);
    $otherStore = Store::query()->create([
        'code' => 'OTHER',
        'name' => 'فروشگاه دیگر',
        'is_active' => true,
    ]);
    $otherBranch = $otherStore->branches()->create([
        'code' => 'OTHER-BR',
        'name' => 'شعبه دیگر',
        'is_active' => true,
    ]);
    $otherWarehouse = Warehouse::query()->create([
        'store_id' => $otherStore->id,
        'branch_id' => $otherBranch->id,
        'code' => 'OTHER-WH',
        'name' => 'انبار دیگر',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('products.index', ['warehouse_id' => $otherWarehouse->id]))
        ->assertSessionHasErrors('warehouse_id');

    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => UserRole::Viewer])->save();

    $this->actingAs($viewer)
        ->get(route('products.create'))
        ->assertForbidden();
});

it('updates a product and rejects stale changes', function () {
    $manager = productManagementUser(UserRole::Manager);
    $product = Product::query()->create(productPayload([
        'barcode' => null,
    ]));
    $payload = productPayload([
        'code' => 'PROD-1001',
        'barcode' => null,
        'name' => 'کالای به‌روزشده',
        'version' => 0,
    ]);

    $this->actingAs($manager)
        ->put(route('products.update', $product), $payload)
        ->assertRedirect(route('products.index'))
        ->assertSessionHas('inertia.flash_data', fn (array $flash) => str_contains(
            $flash['toast']['message'],
            'کالای به‌روزشده',
        ));

    expect($product->refresh()->version)->toBe(1)
        ->and($product->name)->toBe('کالای به‌روزشده');

    $this->actingAs($manager)
        ->put(route('products.update', $product), $payload)
        ->assertSessionHasErrors('product');

    expect($product->refresh()->version)->toBe(1)
        ->and($product->name)->toBe('کالای به‌روزشده');
});
