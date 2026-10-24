<?php

use App\Enums\InventoryMovementType;
use App\Enums\ProductSerialStatus;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\ProductPriceHistory;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function advancedCatalogUser(UserRole $role = UserRole::Manager): User
{
    app(StoreSeeder::class)->run();
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

/**
 * @return array<string, mixed>
 */
function advancedProductPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'ADV-P-001',
        'barcode' => '626900000001',
        'name' => 'کالای پیشرفته',
        'category_id' => null,
        'parent_product_id' => null,
        'variant_color' => null,
        'variant_size' => null,
        'unit' => 'عدد',
        'purchase_price' => '100',
        'sale_price' => '150',
        'tax_rate' => '0',
        'reorder_level' => '2',
        'safety_stock' => '3',
        'reorder_quantity' => '10',
        'max_stock' => '20',
        'stock_policy' => 'min_max',
        'description' => null,
        'is_active' => true,
    ], $overrides);
}

it('manages product categories and filters the catalog by category', function () {
    $manager = advancedCatalogUser();

    $this->actingAs($manager)
        ->post(route('product-categories.store'), [
            'code' => 'CLOTHING',
            'name' => 'پوشاک',
            'description' => 'کالاهای پوشاکی',
            'is_active' => true,
        ])
        ->assertRedirect(route('product-categories.index'));
    $category = ProductCategory::query()->where('code', 'CLOTHING')->firstOrFail();

    $this->actingAs($manager)
        ->post(route('products.store'), advancedProductPayload([
            'category_id' => $category->id,
        ]))
        ->assertRedirect(route('products.index'));

    $this->actingAs($manager)
        ->get(route('products.index', ['category_id' => $category->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.category.id', $category->id)
            ->where('summary.total', 1));
});

it('creates variants as independent stockable products and hides parent from lookups', function () {
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $manager = advancedCatalogUser();

    $this->actingAs($manager)
        ->post(route('products.store'), advancedProductPayload([
            'barcode' => null,
        ]))
        ->assertRedirect(route('products.index'));
    $parent = Product::query()->where('code', 'ADV-P-001')->firstOrFail();

    $this->actingAs($manager)
        ->post(route('products.store'), advancedProductPayload([
            'code' => 'ADV-P-001-BLACK-L',
            'barcode' => '626900000002',
            'parent_product_id' => $parent->id,
            'variant_color' => 'مشکی',
            'variant_size' => 'L',
        ]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $variant = Product::query()->where('code', 'ADV-P-001-BLACK-L')->firstOrFail();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $warehouse = $store->defaultBranch->warehouses()->where('is_default', true)->firstOrFail();

    expect($variant->parent_product_id)->toBe($parent->id)
        ->and($variant->name)->toBe('کالای پیشرفته - مشکی / L')
        ->and($parent->variants()->count())->toBe(1);

    $this->actingAs($manager)
        ->getJson(route('inventory.lookups.products', [
            'warehouse_id' => $warehouse->id,
            'search' => 'ADV-P-001',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $variant->id);

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $parent->id,
        quantity: 1,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'parent-cannot-stock',
        movementType: InventoryMovementType::Opening,
    ))->toThrow(DomainException::class);
});

it('tracks price changes and applies min-max reorder suggestions', function () {
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $manager = advancedCatalogUser();
    $this->actingAs($manager)
        ->post(route('products.store'), advancedProductPayload())
        ->assertRedirect(route('products.index'));
    $product = Product::query()->where('code', 'ADV-P-001')->firstOrFail();

    $this->actingAs($manager)
        ->put(route('products.update', $product), advancedProductPayload([
            'id' => $product->id,
            'sale_price' => '175',
            'version' => $product->version,
        ]))
        ->assertRedirect(route('products.index'));

    expect($product->refresh()->sale_price)->toBe('175.00')
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->count())->toBe(2);

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $warehouse = $store->defaultBranch->warehouses()->where('is_default', true)->firstOrFail();
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 3,
        unitCost: 100,
        movementDate: now()->toDateString(),
        idempotencyKey: 'advanced-stock-policy',
        movementType: InventoryMovementType::Opening,
    );

    $this->actingAs($manager)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.stock_status', 'low_stock')
            ->where('products.data.0.suggested_order_quantity', '17.000'));
});

it('registers batches and serial numbers and voids available serials', function () {
    $manager = advancedCatalogUser();
    $this->actingAs($manager)
        ->post(route('products.store'), advancedProductPayload())
        ->assertRedirect(route('products.index'));
    $product = Product::query()->where('code', 'ADV-P-001')->firstOrFail();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $warehouse = $store->defaultBranch->warehouses()->where('is_default', true)->firstOrFail();

    $this->actingAs($manager)
        ->post(route('products.tracking.batches.store', $product), [
            'batch_number' => 'BATCH-001',
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
        ])
        ->assertRedirect(route('products.tracking', $product));
    $batch = ProductBatch::query()->where('product_id', $product->id)->firstOrFail();

    $this->actingAs($manager)
        ->post(route('products.tracking.serials.store', $product), [
            'serial_numbers_text' => "SN-001\nSN-002",
            'product_batch_id' => $batch->id,
            'warehouse_id' => $warehouse->id,
            'acquired_at' => now()->toDateString(),
        ])
        ->assertRedirect(route('products.tracking', $product));

    $serial = ProductSerial::query()->where('serial_number', 'SN-001')->firstOrFail();

    $this->actingAs($manager)
        ->patch(route('products.tracking.serials.void', [
            'product' => $product,
            'serial' => $serial,
        ]))
        ->assertRedirect(route('products.tracking', $product));

    expect($serial->refresh()->status)->toBe(ProductSerialStatus::Void)
        ->and($batch->serials()->count())->toBe(2)
        ->and(ProductSerial::query()
            ->where('product_id', $product->id)
            ->where('status', ProductSerialStatus::Available->value)
            ->count())->toBe(1);
});
