<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\InventoryProductLookupService;
use App\Services\Inventory\StockCountImportService;
use App\Services\Products\ProductManagementService;
use App\Services\Sales\SalesCheckoutLookupService;
use Database\Seeders\StoreSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function identifierProductPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'IDENT-1',
        'barcode' => '626000000001',
        'name' => 'کالای شناسه',
        'item_type' => 'stock',
        'unit' => 'عدد',
        'purchase_price' => '100',
        'sale_price' => '150',
        'tax_rate' => '0',
        'reorder_level' => '0',
        'safety_stock' => '0',
        'reorder_quantity' => '0',
        'max_stock' => null,
        'stock_policy' => 'simple',
        'track_batches' => false,
        'track_serials' => false,
        'description' => null,
        'is_active' => true,
    ], $overrides);
}

function identifierProductManager(): User
{
    return User::factory()->create(['role' => UserRole::Manager]);
}

function productIdentifierMigration(): object
{
    return require database_path('migrations/2026_11_26_000002_enforce_cross_column_product_identifiers.php');
}

/**
 * @param  callable(object, int, int): mixed  $callback
 */
function withLegacyProductIdentifierCollision(string $firstCode, string $secondBarcode, callable $callback): void
{
    $migration = productIdentifierMigration();
    $migration->down();
    $firstId = DB::table('products')->insertGetId([
        'code' => $firstCode,
        'barcode' => null,
        'name' => 'کالای قدیمی اول',
    ]);
    $secondId = DB::table('products')->insertGetId([
        'code' => 'LEGACY-OTHER',
        'barcode' => $secondBarcode,
        'name' => 'کالای قدیمی دوم',
    ]);

    try {
        $callback($migration, $firstId, $secondId);
    } finally {
        DB::table('products')->whereIn('id', [$firstId, $secondId])->delete();
        $migration->up();
    }
}

it('rejects cross-column create collisions in both directions', function (string $field, string $identifier) {
    $manager = identifierProductManager();
    Product::query()->create(identifierProductPayload([
        'code' => 'BASE-CODE',
        'barcode' => 'BASE-BARCODE',
    ]));

    $this->actingAs($manager)
        ->post(route('products.store'), identifierProductPayload([
            'code' => 'NEW-CODE',
            'barcode' => 'NEW-BARCODE',
            $field => $identifier,
        ]))
        ->assertSessionHasErrors($field);

    expect(Product::query()->count())->toBe(1);
})->with([
    'code matches another barcode' => ['code', 'BASE-BARCODE'],
    'barcode matches another code' => ['barcode', 'BASE-CODE'],
]);

it('rejects cross-column update collisions in both directions', function (string $field, string $identifier) {
    $manager = identifierProductManager();
    Product::query()->create(identifierProductPayload([
        'code' => 'BASE-CODE',
        'barcode' => 'BASE-BARCODE',
    ]));
    $product = Product::query()->create(identifierProductPayload([
        'code' => 'TARGET-CODE',
        'barcode' => 'TARGET-BARCODE',
    ]));

    $this->actingAs($manager)
        ->put(route('products.update', $product), identifierProductPayload([
            'code' => 'TARGET-CODE',
            'barcode' => 'TARGET-BARCODE',
            'version' => 0,
            $field => $identifier,
        ]))
        ->assertSessionHasErrors($field);

    expect($product->refresh()->code)->toBe('TARGET-CODE')
        ->and($product->barcode)->toBe('TARGET-BARCODE')
        ->and($product->version)->toBe(0);
})->with([
    'code matches another barcode' => ['code', 'BASE-BARCODE'],
    'barcode matches another code' => ['barcode', 'BASE-CODE'],
]);

it('normalizes Persian and Arabic digits before cross-column validation', function (string $field, string $identifier) {
    $manager = identifierProductManager();
    Product::query()->create(identifierProductPayload([
        'code' => 'BASE-123',
        'barcode' => '126',
    ]));

    $this->actingAs($manager)
        ->post(route('products.store'), identifierProductPayload([
            'code' => 'NEW-CODE',
            'barcode' => 'NEW-BARCODE',
            $field => $identifier,
        ]))
        ->assertSessionHasErrors($field);

    expect(Product::query()->count())->toBe(1);
})->with([
    'Persian code digits' => ['code', '۱۲۶'],
    'Arabic barcode digits' => ['barcode', 'BASE-١٢٣'],
]);

it('stores normalized Persian and Arabic product identifiers', function () {
    $manager = identifierProductManager();

    $this->actingAs($manager)
        ->post(route('products.store'), identifierProductPayload([
            'code' => '  P-۱۲۳  ',
            'barcode' => 'B-٤٥٦',
        ]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $product = Product::query()->firstOrFail();

    expect($product->code)->toBe('P-123')
        ->and($product->barcode)->toBe('B-456');
});

it('enforces normalized cross-column uniqueness in the product service', function () {
    $manager = identifierProductManager();
    $products = app(ProductManagementService::class);
    Product::query()->create(identifierProductPayload([
        'code' => 'SERVICE-123',
        'barcode' => '626',
    ]));

    expect(fn () => $products->create(identifierProductPayload([
        'code' => '۶۲۶',
        'barcode' => 'SERVICE-NEW',
    ]), $manager))->toThrow(DomainException::class);

    expect(fn () => $products->create(identifierProductPayload([
        'code' => 'SERVICE-NEW',
        'barcode' => 'SERVICE-١٢٣',
    ]), $manager))->toThrow(DomainException::class);

    $target = Product::query()->create(identifierProductPayload([
        'code' => 'SERVICE-TARGET',
        'barcode' => null,
    ]));

    expect(fn () => $products->update($target, identifierProductPayload([
        'code' => '۶۲۶',
        'barcode' => null,
        'version' => $target->version,
    ]), $manager))->toThrow(DomainException::class);

    expect(fn () => $products->update($target, identifierProductPayload([
        'code' => 'SERVICE-TARGET',
        'barcode' => 'SERVICE-١٢٣',
        'version' => $target->version,
    ]), $manager))->toThrow(DomainException::class);

    expect($target->refresh()->code)->toBe('SERVICE-TARGET')
        ->and($target->barcode)->toBeNull()
        ->and($target->version)->toBe(0);
});

it('allows one product to use the same value for code and barcode', function () {
    $manager = identifierProductManager();
    $products = app(ProductManagementService::class);
    $product = $products->create(identifierProductPayload([
        'code' => 'SAME-۱',
        'barcode' => 'SAME-١',
    ]), $manager);

    expect($product->code)->toBe('SAME-1')
        ->and($product->barcode)->toBe('SAME-1');

    $updated = $products->update($product, identifierProductPayload([
        'code' => 'SAME-۲',
        'barcode' => 'SAME-٢',
        'version' => $product->version,
    ]), $manager);

    expect($updated->code)->toBe('SAME-2')
        ->and($updated->barcode)->toBe('SAME-2');
});

it('enforces cross-column uniqueness for direct database inserts and updates', function () {
    DB::table('products')->insert([
        'code' => 'DB-CODE',
        'barcode' => 'DB-BARCODE',
        'name' => 'کالای مستقیم اول',
    ]);
    $secondId = DB::table('products')->insertGetId([
        'code' => 'DB-OTHER',
        'barcode' => 'DB-SECOND',
        'name' => 'کالای مستقیم دوم',
    ]);

    expect(fn () => DB::table('products')->insert([
        'code' => 'DB-BARCODE',
        'barcode' => 'DB-NEW',
        'name' => 'برخورد کد با بارکد',
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->insert([
        'code' => 'DB-NEW',
        'barcode' => 'DB-CODE',
        'name' => 'برخورد بارکد با کد',
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->where('id', $secondId)->update([
        'code' => 'DB-BARCODE',
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->where('id', $secondId)->update([
        'barcode' => 'DB-CODE',
    ]))->toThrow(QueryException::class);

    $second = DB::table('products')->where('id', $secondId)->first();

    expect($second->code)->toBe('DB-OTHER')
        ->and($second->barcode)->toBe('DB-SECOND');

    $sameId = DB::table('products')->insertGetId([
        'code' => 'DB-SAME',
        'barcode' => 'DB-SAME',
        'name' => 'کالای هم‌شناسه',
    ]);
    DB::table('products')->where('id', $sameId)->update([
        'code' => 'DB-SAME-2',
        'barcode' => 'DB-SAME-2',
    ]);
    $same = DB::table('products')->where('id', $sameId)->first();

    expect($same->code)->toBe('DB-SAME-2')
        ->and($same->barcode)->toBe('DB-SAME-2');
});

it('normalizes Persian and Arabic digits in the direct database constraint', function () {
    DB::table('products')->insert([
        'code' => 'DIGIT-EXISTING',
        'barcode' => '۱۲۳۴۵',
        'name' => 'کالای رقم‌دار',
    ]);
    $targetId = DB::table('products')->insertGetId([
        'code' => 'DIGIT-TARGET',
        'barcode' => null,
        'name' => 'کالای رقم‌دار دوم',
    ]);

    expect(fn () => DB::table('products')->insert([
        'code' => '١٢٣٤٥',
        'barcode' => null,
        'name' => 'برخورد رقم‌دار',
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->where('id', $targetId)->update([
        'code' => '12345',
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->where('id', $targetId)->update([
        'barcode' => '۱۲۳۴۵',
    ]))->toThrow(QueryException::class);
});

it('preflights normalized legacy collisions without changing product data', function () {
    withLegacyProductIdentifierCollision('PRE-123', 'PRE-١٢٣', function (object $migration, int $firstId, int $secondId): void {
        $before = DB::table('products')
            ->whereIn('id', [$firstId, $secondId])
            ->orderBy('id')
            ->get()
            ->map(fn (object $product): array => (array) $product)
            ->all();

        expect(fn () => $migration->up())->toThrow(function (RuntimeException $exception) use ($firstId, $secondId): void {
            expect($exception->getMessage())
                ->toContain("normalized value 'PRE-123'")
                ->toContain("product #{$firstId} code='PRE-123'")
                ->toContain("product #{$secondId} barcode='PRE-١٢٣'")
                ->toContain('no product data was changed');
        });

        $after = DB::table('products')
            ->whereIn('id', [$firstId, $secondId])
            ->orderBy('id')
            ->get()
            ->map(fn (object $product): array => (array) $product)
            ->all();

        expect($after)->toBe($before);
    });
});

it('does not auto-select a legacy collision during lookup or stock-count import', function () {
    withLegacyProductIdentifierCollision('LEGACY-ID', 'LEGACY-ID', function (object $migration, int $firstId, int $secondId): void {
        app(StoreSeeder::class)->run();
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $warehouse = $store->defaultBranch->warehouses()->firstOrFail();

        $inventoryMatches = app(InventoryProductLookupService::class)->products(
            $store->id,
            $warehouse->id,
            $store->defaultBranch->id,
            'LEGACY-ID',
        );
        $salesMatches = app(SalesCheckoutLookupService::class)->products(
            $store->id,
            $warehouse->id,
            'LEGACY-ID',
        );

        expect(array_column($inventoryMatches, 'id'))->toHaveCount(2)
            ->and(array_column($inventoryMatches, 'id'))->toContain($firstId, $secondId)
            ->and($salesMatches)->toBe([]);

        $file = UploadedFile::fake()->createWithContent(
            'ambiguous-stock-count.csv',
            "code,quantity\nLEGACY-ID,1\n",
        );

        expect(fn () => app(StockCountImportService::class)->parse($file))
            ->toThrow(DomainException::class, 'شناسهٔ «LEGACY-ID» مبهم است');
    });
});
