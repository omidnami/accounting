<?php

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Enums\ProductSerialStatus;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\InventoryMovementSerial;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\ProductSerial;
use App\Models\StockCount;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryProductLookupService;
use App\Services\Inventory\InventoryTraceabilityService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Inventory\StockCountImportService;
use App\Services\Purchasing\PurchaseLookupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{store: Store, branch: Branch, warehouse: Warehouse}
 */
function b30InventoryStore(string $code): array
{
    $store = Store::query()->create([
        'code' => $code,
        'name' => 'فروشگاه '.$code,
        'is_active' => true,
    ]);
    $branch = $store->branches()->create([
        'code' => $code.'-BR',
        'name' => 'شعبه '.$code,
        'is_default' => true,
        'is_active' => true,
    ]);
    $warehouse = Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'code' => $code.'-WH',
        'name' => 'انبار '.$code,
        'is_default' => true,
        'is_active' => true,
    ]);

    return compact('store', 'branch', 'warehouse');
}

/**
 * @return array{store: Store, branch: Branch, warehouse: Warehouse}
 */
function b30InventoryWarehouse(Store $store, Branch $branch, string $code): array
{
    $warehouse = Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'code' => $code,
        'name' => 'انبار '.$code,
        'is_active' => true,
    ]);

    return compact('store', 'branch', 'warehouse');
}

function b30InventoryProduct(array $overrides = []): Product
{
    static $sequence = 0;
    $sequence++;

    return Product::query()->create(array_replace([
        'code' => 'B30-P-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'barcode' => null,
        'name' => 'کالای '.$sequence,
        'unit' => 'عدد',
        'purchase_price' => '100',
        'sale_price' => '150',
        'tax_rate' => '10',
        'reorder_level' => '0',
        'safety_stock' => '0',
        'reorder_quantity' => '0',
        'stock_policy' => 'simple',
        'is_active' => true,
    ], $overrides));
}

function b30InventoryBalance(
    Store $store,
    Branch $branch,
    Warehouse $warehouse,
    Product $product,
    string $quantity,
    string $value,
): InventoryBalance {
    return InventoryBalance::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'average_cost' => '100.0000',
        'inventory_value' => $value,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function b30InventoryMovement(
    Store $store,
    Branch $branch,
    Warehouse $warehouse,
    Product $product,
    array $overrides = [],
): InventoryMovement {
    $quantity = (string) ($overrides['quantity'] ?? '1');

    return InventoryMovement::query()->create(array_replace([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => InventoryDirection::In,
        'movement_type' => InventoryMovementType::Opening,
        'quantity' => $quantity,
        'unit_cost' => '100.0000',
        'total_cost' => (string) (100 * (float) $quantity),
        'quantity_after' => $quantity,
        'average_cost_after' => '100.0000',
        'value_after' => (string) (100 * (float) $quantity),
        'movement_date' => '2026-10-22',
        'source_type' => null,
        'source_id' => null,
        'idempotency_key' => 'b30-movement-'.Str::uuid(),
    ], $overrides));
}

function b30Supplier(Store $store, string $code, array $partyOverrides = [], array $supplierOverrides = []): Supplier
{
    $party = Party::query()->create(array_replace([
        'store_id' => $store->id,
        'code' => $code,
        'name' => 'طرف حساب '.$code,
        'is_active' => true,
    ], $partyOverrides));

    return Supplier::query()->create(array_replace([
        'party_id' => $party->id,
        'payment_terms' => '30 روز',
        'lead_time_days' => 7,
        'is_active' => true,
    ], $supplierOverrides));
}

it('looks up active leaf products with store, warehouse and branch scoped stock', function () {
    $main = b30InventoryStore('LOOKUP-MAIN');
    $secondWarehouse = b30InventoryWarehouse($main['store'], $main['branch'], 'LOOKUP-SECOND-WH');
    $secondBranch = $main['store']->branches()->create([
        'code' => 'LOOKUP-OTHER-BR',
        'name' => 'شعبه دوم جستجو',
        'is_active' => true,
    ]);
    $secondBranchWarehouse = b30InventoryWarehouse($main['store'], $secondBranch, 'LOOKUP-OTHER-BR-WH');
    $other = b30InventoryStore('LOOKUP-OTHER');

    $alpha = b30InventoryProduct([
        'code' => 'LOOK-100',
        'barcode' => '626100000001',
        'name' => 'Alpha',
    ]);
    $beta = b30InventoryProduct(['code' => 'LOOK-200', 'name' => 'Beta']);
    $inactive = b30InventoryProduct([
        'code' => 'LOOK-300',
        'name' => 'Inactive',
        'is_active' => false,
    ]);
    $parent = b30InventoryProduct(['code' => 'LOOK-PARENT', 'name' => 'Parent']);
    $variant = b30InventoryProduct([
        'code' => 'LOOK-VARIANT',
        'name' => 'Variant',
        'parent_product_id' => $parent->id,
        'variant_color' => 'مشکی',
    ]);

    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $alpha, '2', '200');
    b30InventoryBalance($main['store'], $main['branch'], $secondWarehouse['warehouse'], $alpha, '6', '600');
    b30InventoryBalance($main['store'], $secondBranch, $secondBranchWarehouse['warehouse'], $alpha, '3', '300');
    b30InventoryBalance($other['store'], $other['branch'], $other['warehouse'], $alpha, '7', '700');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $beta, '5', '500');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $inactive, '9', '900');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $variant, '4', '400');

    $lookup = app(InventoryProductLookupService::class);
    $warehouseRows = $lookup->products($main['store']->id, $main['warehouse']->id, $main['branch']->id, '');
    $branchRows = $lookup->products($main['store']->id, null, $main['branch']->id, '');
    $storeRows = $lookup->products($main['store']->id, null, null, '');
    $otherStoreRows = $lookup->products($other['store']->id, $other['warehouse']->id, $other['branch']->id, '');

    expect(array_column($warehouseRows, 'id'))->toBe([$alpha->id, $beta->id, $variant->id])
        ->and(array_column($branchRows, 'id'))->toBe([$alpha->id, $beta->id, $variant->id])
        ->and(array_column($storeRows, 'id'))->toBe([$alpha->id, $beta->id, $variant->id])
        ->and(array_column($otherStoreRows, 'id'))->toBe([$alpha->id, $beta->id, $variant->id])
        ->and($warehouseRows[0])->toMatchArray([
            'code' => 'LOOK-100',
            'barcode' => '626100000001',
            'unit' => 'عدد',
            'is_active' => true,
            'stock_quantity' => '2.000',
        ])
        ->and($branchRows[0]['stock_quantity'])->toBe('8.000')
        ->and($storeRows[0]['stock_quantity'])->toBe('11.000')
        ->and($otherStoreRows[0]['stock_quantity'])->toBe('7.000')
        ->and($lookup->products($main['store']->id, $secondWarehouse['warehouse']->id, null, '')[0]['stock_quantity'])
        ->toBe('6.000')
        ->and($lookup->products($main['store']->id, $main['warehouse']->id, null, 'LOOK-300', true)[0]['id'])
        ->toBe($inactive->id);
});

it('normalizes lookup digits when searching product barcodes', function () {
    $context = b30InventoryStore('LOOKUP-SEARCH');
    $barcodeProduct = b30InventoryProduct([
        'code' => 'LOOK-DIGITS',
        'barcode' => '626123456789',
        'name' => 'Barcode product',
    ]);
    b30InventoryProduct(['code' => 'LOOK-OTHER', 'name' => 'Search decoy']);

    $lookup = app(InventoryProductLookupService::class);
    $digitRows = $lookup->products($context['store']->id, $context['warehouse']->id, null, ' ۶۲۶۱۲۳۴۵۶۷۸۹ ');

    expect(array_column($digitRows, 'id'))->toBe([$barcodeProduct->id]);
});

it('returns catalog rows with category, variants, valuations and reorder calculations', function () {
    $context = b30InventoryStore('CATALOG-DATA');
    $category = ProductCategory::query()->create([
        'code' => 'B30-CATALOG',
        'name' => 'دسته داده کاتالوگ',
        'is_active' => true,
    ]);
    $parent = b30InventoryProduct([
        'code' => 'CATALOG-PARENT',
        'name' => 'Catalog Parent',
        'category_id' => $category->id,
        'reorder_level' => '2',
    ]);
    $variant = b30InventoryProduct([
        'code' => 'CATALOG-VARIANT',
        'name' => 'Catalog Child',
        'category_id' => $category->id,
        'parent_product_id' => $parent->id,
        'variant_size' => 'L',
        'purchase_price' => '80.125',
        'sale_price' => '120.50',
        'tax_rate' => '9',
        'reorder_level' => '5',
        'reorder_quantity' => '7',
    ]);
    b30InventoryBalance($context['store'], $context['branch'], $context['warehouse'], $variant, '8', '800');

    $page = app(ProductCatalogService::class)->paginate($context['store']->id, [
        'warehouse_id' => $context['warehouse']->id,
        'search' => 'Catalog',
    ]);

    expect($page->total())->toBe(2)
        ->and($page->items()[0])->toMatchArray([
            'id' => $variant->id,
            'code' => 'CATALOG-VARIANT',
            'variants_count' => 0,
            'category' => [
                'id' => $category->id,
                'code' => 'B30-CATALOG',
                'name' => 'دسته داده کاتالوگ',
            ],
            'purchase_price' => '80.13',
            'sale_price' => '120.50',
            'tax_rate' => '9.00',
            'reorder_level' => '5.000',
            'reorder_quantity' => '7.000',
            'suggested_order_quantity' => '7.000',
            'total_quantity' => '8.000',
            'average_cost' => '100.0000',
            'inventory_value' => '800.00',
            'stock_status' => 'in_stock',
        ])
        ->and($page->items()[1])->toMatchArray([
            'id' => $parent->id,
            'variants_count' => 1,
            'total_quantity' => '0.000',
            'average_cost' => '0.0000',
            'inventory_value' => '0.00',
            'stock_status' => 'out_of_stock',
        ]);
});

it('filters catalog rows and summaries by status, category, stock and warehouse', function () {
    $main = b30InventoryStore('CATALOG-FILTER');
    $secondWarehouse = b30InventoryWarehouse($main['store'], $main['branch'], 'CATALOG-SECOND-WH');
    $other = b30InventoryStore('CATALOG-OTHER');
    $category = ProductCategory::query()->create([
        'code' => 'B30-CATALOG-FILTER',
        'name' => 'دسته فیلتر',
        'is_active' => true,
    ]);
    $otherCategory = ProductCategory::query()->create([
        'code' => 'B30-CATALOG-OTHER',
        'name' => 'دسته دیگر',
        'is_active' => true,
    ]);
    $healthy = b30InventoryProduct([
        'code' => 'CAT-HEALTHY',
        'barcode' => '626300000001',
        'name' => 'Healthy Catalog',
        'category_id' => $category->id,
        'reorder_level' => '5',
    ]);
    $low = b30InventoryProduct([
        'code' => 'CAT-LOW',
        'name' => 'Low Catalog',
        'category_id' => $category->id,
        'reorder_level' => '2',
        'safety_stock' => '5',
        'reorder_quantity' => '10',
        'max_stock' => '20',
        'stock_policy' => 'min_max',
    ]);
    $out = b30InventoryProduct([
        'code' => 'CAT-OUT',
        'name' => 'Out Catalog',
        'category_id' => $category->id,
    ]);
    $inactive = b30InventoryProduct([
        'code' => 'CAT-INACTIVE',
        'name' => 'Inactive Catalog',
        'category_id' => $category->id,
        'is_active' => false,
    ]);
    $otherProduct = b30InventoryProduct([
        'code' => 'CAT-OTHER',
        'name' => 'Other Catalog',
        'category_id' => $otherCategory->id,
    ]);

    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $healthy, '10', '1200');
    b30InventoryBalance($main['store'], $main['branch'], $secondWarehouse['warehouse'], $healthy, '7', '700');
    b30InventoryBalance($other['store'], $other['branch'], $other['warehouse'], $healthy, '100', '10000');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $low, '5', '500');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $otherProduct, '1', '100');

    $catalog = app(ProductCatalogService::class);
    $firstWarehouse = $catalog->paginate($main['store']->id, ['warehouse_id' => $main['warehouse']->id]);
    $secondWarehousePage = $catalog->paginate($main['store']->id, ['warehouse_id' => $secondWarehouse['warehouse']->id]);
    $allMainWarehouses = $catalog->paginate($main['store']->id, []);
    $lowPage = $catalog->paginate($main['store']->id, [
        'warehouse_id' => $main['warehouse']->id,
        'stock_status' => 'low_stock',
    ]);
    $activeOutPage = $catalog->paginate($main['store']->id, [
        'warehouse_id' => $main['warehouse']->id,
        'category_id' => $category->id,
        'status' => 'active',
        'stock_status' => 'out_of_stock',
    ]);
    $searchPage = $catalog->paginate($main['store']->id, ['search' => '626300000001']);

    expect($firstWarehouse->total())->toBe(5)
        ->and(collect($firstWarehouse->items())->firstWhere('code', 'CAT-HEALTHY'))->toMatchArray([
            'total_quantity' => '10.000',
            'inventory_value' => '1200.00',
            'stock_status' => 'in_stock',
        ])
        ->and(collect($secondWarehousePage->items())->firstWhere('code', 'CAT-HEALTHY')['total_quantity'])->toBe('7.000')
        ->and(collect($allMainWarehouses->items())->firstWhere('code', 'CAT-HEALTHY')['total_quantity'])->toBe('17.000')
        ->and(array_column($lowPage->items(), 'code'))->toBe(['CAT-LOW'])
        ->and($lowPage->items()[0])->toMatchArray([
            'stock_status' => 'low_stock',
            'suggested_order_quantity' => '15.000',
        ])
        ->and(array_column($activeOutPage->items(), 'code'))->toBe(['CAT-OUT'])
        ->and(array_column($searchPage->items(), 'code'))->toBe(['CAT-HEALTHY'])
        ->and($catalog->summary($main['store']->id, $main['warehouse']->id))->toBe([
            'total' => 5,
            'in_stock' => 3,
            'low_stock' => 1,
            'out_of_stock' => 2,
            'inventory_value' => '1800.00',
        ])
        ->and($catalog->summary($main['store']->id, $main['warehouse']->id, $category->id))->toBe([
            'total' => 4,
            'in_stock' => 2,
            'low_stock' => 1,
            'out_of_stock' => 2,
            'inventory_value' => '1700.00',
        ])
        ->and($catalog->summary($other['store']->id, $other['warehouse']->id)['inventory_value'])->toBe('10000.00');
});

it('parses localized stock count CSV rows into normalized product data', function () {
    $first = b30InventoryProduct([
        'code' => 'COUNT-100',
        'name' => 'کالای شمارش اول',
        'unit' => 'کارتن',
    ]);
    $second = b30InventoryProduct([
        'code' => 'COUNT-200',
        'barcode' => '626200000002',
        'name' => 'کالای شمارش دوم',
    ]);
    $file = UploadedFile::fake()->createWithContent(
        'localized-stock-count.csv',
        "\xEF\xBB\xBFکد کالا;مقدار شمارش;بهای واحد\r\n"
        ."COUNT-100;\" ۳٫۵ \";۱۰٬۰۰۰٫۵\r\n"
        ."۶۲۶۲۰۰۰۰۰۰۰۲;۲;\r\n",
    );

    $rows = app(StockCountImportService::class)->parse($file);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'product_id' => $first->id,
            'code' => 'COUNT-100',
            'name' => 'کالای شمارش اول',
            'unit' => 'کارتن',
            'counted_quantity' => '3.500',
            'unit_cost' => '10000.5000',
        ])
        ->and($rows[1])->toBe([
            'product_id' => $second->id,
            'code' => 'COUNT-200',
            'name' => 'کالای شمارش دوم',
            'unit' => 'عدد',
            'counted_quantity' => '2.000',
            'unit_cost' => null,
        ]);
});

it('excludes inactive and parent products and rejects duplicate or unknown stock count rows', function () {
    $inactive = b30InventoryProduct(['code' => 'COUNT-INACTIVE', 'is_active' => false]);
    $parent = b30InventoryProduct(['code' => 'COUNT-PARENT']);
    b30InventoryProduct([
        'code' => 'COUNT-CHILD',
        'parent_product_id' => $parent->id,
        'variant_color' => 'آبی',
    ]);
    $valid = b30InventoryProduct(['code' => 'COUNT-VALID', 'barcode' => '626200000003']);
    $service = app(StockCountImportService::class);

    $inactiveFile = UploadedFile::fake()->createWithContent('inactive.csv', "code,quantity\n{$inactive->code},1\n");
    $parentFile = UploadedFile::fake()->createWithContent('parent.csv', "code,quantity\n{$parent->code},1\n");
    $duplicateFile = UploadedFile::fake()->createWithContent(
        'duplicate.csv',
        "code,quantity\n{$valid->code},1\n{$valid->barcode},2\n",
    );
    $unknownFile = UploadedFile::fake()->createWithContent('unknown.csv', "code,quantity\nCOUNT-MISSING,1\n");
    $validFile = UploadedFile::fake()->createWithContent('valid.csv', "code,quantity\n{$valid->barcode},4\n");

    expect(fn () => $service->parse($inactiveFile))->toThrow(DomainException::class, 'پیدا نشد')
        ->and(fn () => $service->parse($parentFile))->toThrow(DomainException::class, 'پیدا نشد')
        ->and(fn () => $service->parse($duplicateFile))->toThrow(DomainException::class, 'تکراری')
        ->and(fn () => $service->parse($unknownFile))->toThrow(DomainException::class, 'پیدا نشد')
        ->and($service->parse($validFile)[0]['product_id'])->toBe($valid->id);
});

it('rejects an ambiguous legacy identifier instead of choosing a stock count product', function () {
    $migration = require database_path('migrations/2026_11_26_000002_enforce_cross_column_product_identifiers.php');
    $migration->down();
    $firstId = DB::table('products')->insertGetId([
        'code' => 'COUNT-LEGACY',
        'name' => 'کالای قدیمی شمارش',
    ]);
    $secondId = DB::table('products')->insertGetId([
        'code' => 'COUNT-LEGACY-OTHER',
        'barcode' => 'COUNT-LEGACY',
        'name' => 'کالای قدیمی دوم شمارش',
    ]);
    $file = UploadedFile::fake()->createWithContent(
        'ambiguous-stock-count.csv',
        "code,quantity\nCOUNT-LEGACY,1\n",
    );

    try {
        expect(fn () => app(StockCountImportService::class)->parse($file))
            ->toThrow(DomainException::class, 'شناسهٔ «COUNT-LEGACY» مبهم است');
    } finally {
        DB::table('products')->whereIn('id', [$firstId, $secondId])->delete();
        $migration->up();
    }
});

it('groups traceability by product and maps batches and serials only for the requested source', function () {
    $context = b30InventoryStore('TRACE-CONTRACT');
    $batchProduct = b30InventoryProduct([
        'code' => 'TRACE-BATCH-PRODUCT',
        'track_batches' => true,
        'track_serials' => true,
    ]);
    $serialProduct = b30InventoryProduct([
        'code' => 'TRACE-SERIAL-PRODUCT',
        'track_serials' => true,
    ]);
    $firstBatch = ProductBatch::query()->create([
        'product_id' => $batchProduct->id,
        'batch_number' => 'TRACE-BATCH-A',
        'expiry_date' => '2027-01-31',
    ]);
    $secondBatch = ProductBatch::query()->create([
        'product_id' => $batchProduct->id,
        'batch_number' => 'TRACE-BATCH-B',
    ]);
    $serials = collect(['TRACE-A-1', 'TRACE-A-2', 'TRACE-B-1'])
        ->map(fn (string $number, int $index): ProductSerial => ProductSerial::query()->create([
            'product_id' => $batchProduct->id,
            'product_batch_id' => $index < 2 ? $firstBatch->id : $secondBatch->id,
            'warehouse_id' => $context['warehouse']->id,
            'serial_number' => $number,
            'status' => ProductSerialStatus::Available,
        ]));
    $unbatchedSerial = ProductSerial::query()->create([
        'product_id' => $serialProduct->id,
        'warehouse_id' => $context['warehouse']->id,
        'serial_number' => 'TRACE-FREE-1',
        'status' => ProductSerialStatus::Available,
    ]);

    $source = (new Invoice)->forceFill(['id' => 900]);
    $otherInvoice = (new Invoice)->forceFill(['id' => 901]);
    $otherType = (new StockCount)->forceFill(['id' => 900]);
    $batchMovement = b30InventoryMovement($context['store'], $context['branch'], $context['warehouse'], $batchProduct, [
        'quantity' => '3',
        'source_type' => $source->getMorphClass(),
        'source_id' => $source->getKey(),
    ]);
    InventoryMovementBatch::query()->create([
        'inventory_movement_id' => $batchMovement->id,
        'product_batch_id' => $firstBatch->id,
        'quantity' => '2',
        'unit_cost' => '100.0000',
        'total_cost' => '200.00',
    ]);
    InventoryMovementBatch::query()->create([
        'inventory_movement_id' => $batchMovement->id,
        'product_batch_id' => $secondBatch->id,
        'quantity' => '1',
        'unit_cost' => '100.0000',
        'total_cost' => '100.00',
    ]);
    foreach ($serials as $serial) {
        InventoryMovementSerial::query()->create([
            'inventory_movement_id' => $batchMovement->id,
            'product_serial_id' => $serial->id,
        ]);
    }
    $unbatchedMovement = b30InventoryMovement($context['store'], $context['branch'], $context['warehouse'], $serialProduct, [
        'source_type' => $source->getMorphClass(),
        'source_id' => $source->getKey(),
    ]);
    InventoryMovementSerial::query()->create([
        'inventory_movement_id' => $unbatchedMovement->id,
        'product_serial_id' => $unbatchedSerial->id,
    ]);
    b30InventoryMovement($context['store'], $context['branch'], $context['warehouse'], $batchProduct, [
        'quantity' => '9',
        'source_type' => $otherInvoice->getMorphClass(),
        'source_id' => $otherInvoice->getKey(),
    ]);
    b30InventoryMovement($context['store'], $context['branch'], $context['warehouse'], $batchProduct, [
        'quantity' => '8',
        'source_type' => $otherType->getMorphClass(),
        'source_id' => $otherType->getKey(),
    ]);

    $trace = app(InventoryTraceabilityService::class)->forSource($source);

    expect($trace)->toHaveCount(2)
        ->and($trace[0])->toBe([
            [
                'batch_id' => $firstBatch->id,
                'batch_number' => 'TRACE-BATCH-A',
                'expiry_date' => '2027-01-31',
                'quantity' => '2.000',
                'serials' => ['TRACE-A-1', 'TRACE-A-2'],
            ],
            [
                'batch_id' => $secondBatch->id,
                'batch_number' => 'TRACE-BATCH-B',
                'expiry_date' => null,
                'quantity' => '1.000',
                'serials' => ['TRACE-B-1'],
            ],
        ])
        ->and($trace[1])->toBe([[
            'batch_id' => null,
            'batch_number' => null,
            'expiry_date' => null,
            'quantity' => '1.000',
            'serials' => ['TRACE-FREE-1'],
        ]])
        ->and(app(InventoryTraceabilityService::class)->forSource($otherInvoice))->toHaveCount(1)
        ->and(app(InventoryTraceabilityService::class)->forSource($otherType))->toHaveCount(1);
});

it('looks up only active suppliers whose active party belongs to the store', function () {
    $main = b30InventoryStore('SUPPLIER-LOOKUP');
    $other = b30InventoryStore('SUPPLIER-OTHER');
    $first = b30Supplier($main['store'], 'SUP-100', [
        'name' => 'تأمین‌کننده اول',
        'phone' => '02112345678',
        'national_id' => '1234567890',
    ], [
        'payment_terms' => 'نقدی',
        'lead_time_days' => 3,
    ]);
    $second = b30Supplier($main['store'], 'SUP-200', ['name' => 'تأمین‌کننده ویژه']);
    $inactiveSupplier = b30Supplier($main['store'], 'SUP-INACTIVE', [], ['is_active' => false]);
    $inactivePartySupplier = b30Supplier($main['store'], 'SUP-INACTIVE-PARTY', ['is_active' => false]);
    b30Supplier($other['store'], 'SUP-OTHER-STORE');

    $lookup = app(PurchaseLookupService::class);
    $rows = $lookup->suppliers($main['store']->id, '');
    $allRows = $lookup->suppliers($main['store']->id, '', true);
    $persianNationalIdRows = $lookup->suppliers($main['store']->id, '۱۲۳۴۵۶۷۸۹۰');
    $arabicPhoneRows = $lookup->suppliers($main['store']->id, '٠٢١١٢٣٤٥٦٧٨');
    $nameRows = $lookup->suppliers($main['store']->id, 'ویژه');

    expect(array_column($rows, 'id'))->toBe([$first->id, $second->id])
        ->and($rows[0])->toBe([
            'id' => $first->id,
            'code' => 'SUP-100',
            'name' => 'تأمین‌کننده اول',
            'phone' => '02112345678',
            'payment_terms' => 'نقدی',
            'lead_time_days' => 3,
            'is_active' => true,
            'status_label' => 'فعال',
        ])
        ->and(array_column($allRows, 'id'))->toBe([
            $first->id,
            $second->id,
            $inactiveSupplier->id,
            $inactivePartySupplier->id,
        ])
        ->and(collect($allRows)->last())->toMatchArray([
            'id' => $inactivePartySupplier->id,
            'is_active' => false,
            'status_label' => 'غیرفعال',
        ])
        ->and(array_column($persianNationalIdRows, 'id'))->toBe([$first->id])
        ->and(array_column($arabicPhoneRows, 'id'))->toBe([$first->id])
        ->and(array_column($nameRows, 'id'))->toBe([$second->id]);
});

it('looks up purchase products with leaf, active, search and warehouse stock contracts', function () {
    $main = b30InventoryStore('PURCHASE-LOOKUP');
    $secondWarehouse = b30InventoryWarehouse($main['store'], $main['branch'], 'PURCHASE-SECOND-WH');
    $other = b30InventoryStore('PURCHASE-OTHER');
    $alpha = b30InventoryProduct([
        'code' => 'PUR-100',
        'barcode' => '626400000001',
        'name' => 'Alpha Purchase',
        'purchase_price' => '125.50',
        'tax_rate' => '9',
        'track_batches' => true,
        'track_serials' => true,
    ]);
    $beta = b30InventoryProduct(['code' => 'PUR-200', 'name' => 'Beta Purchase']);
    b30InventoryProduct([
        'code' => 'PUR-INACTIVE',
        'name' => 'Inactive Purchase',
        'is_active' => false,
    ]);
    $parent = b30InventoryProduct(['code' => 'PUR-PARENT', 'name' => 'Parent Purchase']);
    $variant = b30InventoryProduct([
        'code' => 'PUR-VARIANT',
        'name' => 'Variant Purchase',
        'parent_product_id' => $parent->id,
        'variant_color' => 'سبز',
    ]);

    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $alpha, '2', '251');
    b30InventoryBalance($main['store'], $main['branch'], $secondWarehouse['warehouse'], $alpha, '5', '627.50');
    b30InventoryBalance($other['store'], $other['branch'], $other['warehouse'], $alpha, '100', '12550');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $beta, '3', '300');
    b30InventoryBalance($main['store'], $main['branch'], $main['warehouse'], $variant, '4', '400');

    $lookup = app(PurchaseLookupService::class);
    $rows = $lookup->products($main['store']->id, $main['warehouse']->id, '');
    $secondWarehouseRows = $lookup->products($main['store']->id, $secondWarehouse['warehouse']->id, '');
    $otherStoreRows = $lookup->products($other['store']->id, $other['warehouse']->id, '');
    $searchRows = $lookup->products($main['store']->id, $main['warehouse']->id, '۶۲۶۴۰۰۰۰۰۰۰۱');

    expect(array_column($rows, 'id'))->toBe([$alpha->id, $beta->id, $variant->id])
        ->and($rows[0])->toMatchArray([
            'code' => 'PUR-100',
            'barcode' => '626400000001',
            'unit' => 'عدد',
            'purchase_price' => '125.50',
            'tax_rate' => '9.00',
            'stock_quantity' => '2.000',
            'track_batches' => true,
            'track_serials' => true,
        ])
        ->and($secondWarehouseRows[0]['stock_quantity'])->toBe('5.000')
        ->and($otherStoreRows[0]['stock_quantity'])->toBe('100.000')
        ->and(array_column($searchRows, 'id'))->toBe([$alpha->id]);
});
