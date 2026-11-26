<?php

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Products\ProductManagementService;
use Database\Seeders\StoreSeeder;
use Illuminate\Database\QueryException;

/**
 * @return array<string, mixed>
 */
function b30ManagementPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'MANAGEMENT-100',
        'barcode' => '626500000001',
        'name' => 'کالای مدیریت مستقیم',
        'unit' => 'عدد',
        'purchase_price' => '100',
        'sale_price' => '150',
        'tax_rate' => '9',
        'reorder_level' => '5',
        'safety_stock' => '0',
        'reorder_quantity' => '10',
        'stock_policy' => 'simple',
        'track_batches' => false,
        'track_serials' => false,
        'is_active' => true,
    ], $overrides);
}

function b30ManagementActor(): User
{
    return User::factory()->create(['role' => UserRole::Manager]);
}

it('creates and updates products with normalized identifiers, versions and price history', function () {
    $actor = b30ManagementActor();
    $service = app(ProductManagementService::class);

    $product = $service->create(b30ManagementPayload([
        'code' => '  MANAGEMENT-۱۲۳  ',
        'barcode' => '  BAR-٤٥٦  ',
        'purchase_price' => '100.5',
        'sale_price' => '150.5',
        'tax_rate' => '9',
        'reorder_level' => '5',
    ]), $actor);

    expect($product->code)->toBe('MANAGEMENT-123')
        ->and($product->barcode)->toBe('BAR-456')
        ->and($product->purchase_price)->toBe('100.50')
        ->and($product->sale_price)->toBe('150.50')
        ->and($product->tax_rate)->toBe('9.00')
        ->and($product->reorder_level)->toBe('5.000')
        ->and($product->version)->toBe(0)
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->firstOrFail())->toMatchArray([
            'purchase_price' => '100.50',
            'sale_price' => '150.50',
            'source' => 'create',
            'changed_by' => $actor->id,
        ]);

    $updated = $service->update($product, b30ManagementPayload([
        'code' => 'MANAGEMENT-123',
        'barcode' => 'BAR-456',
        'name' => 'کالای به‌روزشده',
        'purchase_price' => '100.50',
        'sale_price' => '175',
        'tax_rate' => '9',
        'reorder_level' => '5',
        'version' => 0,
    ]), $actor);

    expect($updated->name)->toBe('کالای به‌روزشده')
        ->and($updated->sale_price)->toBe('175.00')
        ->and($updated->version)->toBe(1)
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->count())->toBe(2)
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->latest('id')->firstOrFail())->toMatchArray([
            'purchase_price' => '100.50',
            'sale_price' => '175.00',
            'source' => 'update',
            'changed_by' => $actor->id,
        ]);

    expect(fn () => $service->update($updated, b30ManagementPayload([
        'code' => 'MANAGEMENT-123',
        'barcode' => 'BAR-456',
        'name' => 'تغییر کهنه',
        'sale_price' => '200',
        'version' => 0,
    ]), $actor))->toThrow(DomainException::class, 'هم‌زمان');

    expect($updated->refresh()->name)->toBe('کالای به‌روزشده')
        ->and($updated->sale_price)->toBe('175.00')
        ->and($updated->version)->toBe(1)
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->count())->toBe(2);
});

it('creates variants through the service and enforces parent history and duplicate rules', function () {
    $actor = b30ManagementActor();
    $service = app(ProductManagementService::class);
    $parent = $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-PARENT',
        'barcode' => null,
    ]), $actor);

    expect($service->canAddVariant($parent))->toBeTrue();

    $variant = $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-VARIANT',
        'barcode' => null,
        'name' => $parent->name,
        'parent_product_id' => $parent->id,
        'variant_color' => 'مشکی',
        'variant_size' => 'L',
    ]), $actor);

    expect($variant->parent_product_id)->toBe($parent->id)
        ->and($variant->name)->toBe('کالای مدیریت مستقیم - مشکی / L')
        ->and($parent->variants()->count())->toBe(1)
        ->and($service->canAddVariant($variant))->toBeFalse();

    expect(fn () => $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-VARIANT-DUPLICATE',
        'barcode' => null,
        'parent_product_id' => $parent->id,
        'variant_color' => 'مشکی',
        'variant_size' => 'L',
    ]), $actor))->toThrow(DomainException::class, 'این ترکیب رنگ و اندازه');

    expect(fn () => $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-VARIANT-MISSING-LABEL',
        'barcode' => null,
        'parent_product_id' => $parent->id,
    ]), $actor))->toThrow(DomainException::class, 'حداقل رنگ یا اندازه');

    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = Branch::query()->where('code', 'MAIN')->firstOrFail();
    $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

    InventoryMovement::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $parent->id,
        'direction' => InventoryDirection::In,
        'movement_type' => InventoryMovementType::Opening,
        'quantity' => '1',
        'unit_cost' => '100.0000',
        'total_cost' => '100.00',
        'quantity_after' => '1',
        'average_cost_after' => '100.0000',
        'value_after' => '100.00',
        'movement_date' => '2026-10-22',
        'idempotency_key' => 'management-parent-history',
    ]);

    expect($service->canAddVariant($parent))->toBeFalse();

    expect(fn () => $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-VARIANT-AFTER-STOCK',
        'barcode' => null,
        'parent_product_id' => $parent->id,
        'variant_color' => 'آبی',
    ]), $actor))->toThrow(DomainException::class, 'سابقهٔ موجودی');

    expect(Product::query()->where('code', 'MANAGEMENT-VARIANT-AFTER-STOCK')->exists())->toBeFalse();
});

it('rolls back product writes and price history when a later transaction step fails', function () {
    $actor = b30ManagementActor();
    $invalidActor = (new User)->forceFill(['id' => 999999]);
    $service = app(ProductManagementService::class);

    expect(fn () => $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-ROLLBACK-CREATE',
    ]), $invalidActor))->toThrow(QueryException::class);

    expect(Product::query()->where('code', 'MANAGEMENT-ROLLBACK-CREATE')->exists())->toBeFalse()
        ->and(ProductPriceHistory::query()->count())->toBe(0);

    $product = $service->create(b30ManagementPayload([
        'code' => 'MANAGEMENT-ROLLBACK-UPDATE',
        'barcode' => null,
    ]), $actor);

    expect(fn () => $service->update($product, b30ManagementPayload([
        'code' => 'MANAGEMENT-ROLLBACK-UPDATE',
        'barcode' => null,
        'name' => 'نام برگشت‌خورده',
        'sale_price' => '999',
        'version' => $product->version,
    ]), $invalidActor))->toThrow(QueryException::class);

    expect($product->refresh()->name)->toBe('کالای مدیریت مستقیم')
        ->and($product->sale_price)->toBe('150.00')
        ->and($product->version)->toBe(0)
        ->and(ProductPriceHistory::query()->where('product_id', $product->id)->count())->toBe(1);
});
