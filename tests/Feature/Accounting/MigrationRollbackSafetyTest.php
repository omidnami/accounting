<?php

use App\Models\Branch;
use App\Models\InventoryAlert;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Store;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Facades\DB;

it('blocks party ownership rollback while parties exist', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    Party::query()->create([
        'store_id' => $store->id,
        'code' => 'ROLLBACK-PARTY',
        'name' => 'Party',
    ]);
    $migration = require database_path('migrations/2026_10_22_600001_scope_existing_parties_to_store.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'party store ownership');
});

it('blocks catalog rollback while batch or serial data exists', function () {
    $product = Product::query()->create(['code' => 'ROLLBACK-P', 'name' => 'Product']);
    ProductBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'B-1']);
    $migration = require database_path('migrations/2026_10_24_000007_add_catalog_variants_serial_batch_and_price_history.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'batch, serial or price-history data');
});

it('blocks invoice enum rollback while return documents exist', function () {
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = Branch::query()->where('store_id', $store->id)->firstOrFail();
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    DB::table('invoices')->insert([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $year->id,
        'invoice_number' => 'ROLLBACK-RETURN',
        'type' => 'sales_return',
        'invoice_date' => $year->starts_on->toDateString(),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_10_22_700001_extend_sales_and_receipt_allocations.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'sales returns');
});

it('makes tracking migration retry-safe and blocks destructive rollback', function () {
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $product = Product::query()->create([
        'code' => 'ROLLBACK-TRACKED',
        'name' => 'Tracked product',
        'track_batches' => true,
    ]);
    $batch = ProductBatch::query()->create([
        'product_id' => $product->id,
        'batch_number' => 'TRACKED-BATCH',
    ]);
    InventoryAlert::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'product_batch_id' => $batch->id,
        'type' => 'expiring',
        'severity' => 'warning',
        'message' => 'Expiring',
    ]);
    $migration = require database_path('migrations/2026_10_24_000009_add_batch_serial_movements_print_agent_and_expiry_alerts.php');

    $migration->up();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'live inventory data');
});
