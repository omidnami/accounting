<?php

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryOpening;
use App\Models\InventoryTransfer;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Inventory\OpeningInventoryService;
use App\Services\Inventory\StockCountService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return array<string, mixed> */
function canonicalIdempotencyFoundation(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $otherUser = User::factory()->create(['role' => UserRole::Manager]);

    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CANONICAL-CUSTOMER',
        'name' => 'Canonical customer',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => '1000000.00',
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CANONICAL-SUPPLIER',
        'name' => 'Canonical supplier',
    ]);
    $supplier = Supplier::query()->create(['party_id' => $supplierParty->id]);
    $product = Product::query()->create([
        'code' => 'CANONICAL-PRODUCT',
        'name' => 'Canonical product',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
    ]);

    return compact(
        'store',
        'branch',
        'warehouse',
        'year',
        'manager',
        'otherUser',
        'customer',
        'customerParty',
        'supplier',
        'supplierParty',
        'product',
    );
}

it('canonicalizes every material inventory movement field and checks the key before master data', function () {
    $context = canonicalIdempotencyFoundation();
    $product = Product::query()->create([
        'code' => 'CANONICAL-TRACKED',
        'name' => 'Canonical tracked product',
        'purchase_price' => 100,
        'sale_price' => 200,
        'track_batches' => true,
        'track_serials' => true,
    ]);
    $batch = ProductBatch::query()->create([
        'product_id' => $product->id,
        'batch_number' => 'CANONICAL-BATCH-1',
    ]);
    $otherBatch = ProductBatch::query()->create([
        'product_id' => $product->id,
        'batch_number' => 'CANONICAL-BATCH-2',
    ]);
    $service = app(InventoryService::class);
    $arguments = [
        'warehouseId' => $context['warehouse']->id,
        'productId' => $product->id,
        'quantity' => 1,
        'unitCost' => 100,
        'movementDate' => $context['year']->starts_on->toDateString(),
        'idempotencyKey' => 'canonical-movement',
        'movementType' => InventoryMovementType::Opening,
        'createdBy' => $context['manager']->id,
        'reference' => 'REF-1',
        'description' => 'Opening description',
        'productBatchId' => $batch->id,
        'serialNumbers' => ['SERIAL-1'],
    ];
    $receive = fn (array $overrides = []) => $service->receive(...array_replace($arguments, $overrides));

    $first = $receive();
    $product->update(['is_active' => false]);
    $context['warehouse']->update(['is_active' => false]);

    expect($receive()->is($first))->toBeTrue()
        ->and($first->idempotency_request_hash)->toMatch('/^[a-f0-9]{64}$/');

    $changes = [
        ['unitCost' => 101],
        ['reference' => 'REF-2'],
        ['description' => 'Changed description'],
        ['createdBy' => $context['otherUser']->id],
        ['serialNumbers' => ['SERIAL-2']],
        ['productBatchId' => $otherBatch->id],
    ];

    foreach ($changes as $change) {
        expect(fn () => $receive($change))->toThrow(DomainException::class, 'درخواست متفاوت');
    }

    expect(InventoryMovement::query()->count())->toBe(1);
});

it('uses persisted state for legacy null movement hashes without allowing blind success', function () {
    $context = canonicalIdempotencyFoundation();
    $product = Product::query()->create([
        'code' => 'CANONICAL-LEGACY-TRACKED',
        'name' => 'Canonical legacy tracked product',
        'purchase_price' => 100,
        'sale_price' => 200,
        'track_batches' => true,
        'track_serials' => true,
    ]);
    $batch = ProductBatch::query()->create([
        'product_id' => $product->id,
        'batch_number' => 'CANONICAL-LEGACY-BATCH',
    ]);
    $service = app(InventoryService::class);
    $arguments = [
        'warehouseId' => $context['warehouse']->id,
        'productId' => $product->id,
        'quantity' => 1,
        'unitCost' => 100,
        'movementDate' => $context['year']->starts_on->toDateString(),
        'idempotencyKey' => 'canonical-legacy-movement',
        'movementType' => InventoryMovementType::Opening,
        'createdBy' => $context['manager']->id,
        'reference' => 'LEGACY-REF',
        'description' => 'Legacy description',
        'productBatchId' => $batch->id,
        'serialNumbers' => ['LEGACY-SERIAL'],
    ];
    $receive = fn (array $overrides = []) => $service->receive(...array_replace($arguments, $overrides));

    $first = $receive();
    DB::table('inventory_movements')->where('id', $first->id)->update(['idempotency_request_hash' => null]);
    $product->update(['is_active' => false]);

    expect($receive()->is($first))->toBeTrue()
        ->and(fn () => $receive(['reference' => 'CHANGED']))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(fn () => $receive(['createdBy' => $context['otherUser']->id]))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('canonicalizes sales drafts and supports exact legacy retries after master data is deactivated', function () {
    $context = canonicalIdempotencyFoundation();
    $service = app(SalesService::class);
    $attributes = [
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['year']->id,
        'customer_id' => $context['customer']->id,
        'invoice_date' => $context['year']->starts_on->toDateString(),
        'notes' => 'Sales notes',
        'created_by' => $context['manager']->id,
    ];
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => 2,
        'unit_price' => 200,
        'discount_amount' => 0,
        'tax_rate' => 10,
    ]];
    $create = fn (array $attributeOverrides = [], array $itemOverrides = []) => $service->createDraft(
        array_replace($attributes, $attributeOverrides),
        [array_replace($items[0], $itemOverrides)],
        'canonical-sales-draft',
    );

    $first = $create();
    $context['customer']->update(['is_active' => false]);
    $context['customerParty']->update(['is_active' => false]);
    $context['product']->update(['is_active' => false]);

    expect($create()->is($first))->toBeTrue()
        ->and(fn () => $create(['notes' => 'Changed']))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(fn () => $create(['created_by' => $context['otherUser']->id]))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(fn () => $create([], ['quantity' => 3]))->toThrow(DomainException::class, 'درخواست متفاوت');

    DB::table('invoices')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($create()->is($first))->toBeTrue()
        ->and(fn () => $create([], ['tax_rate' => 20]))->toThrow(DomainException::class, 'درخواست متفاوت');
});

it('canonicalizes purchase drafts including tracking and additional costs', function () {
    $context = canonicalIdempotencyFoundation();
    $service = app(PurchaseService::class);
    $attributes = [
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['year']->id,
        'supplier_id' => $context['supplier']->id,
        'invoice_date' => $context['year']->starts_on->toDateString(),
        'notes' => 'Purchase notes',
        'created_by' => $context['manager']->id,
    ];
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => 2,
        'unit_price' => 100,
        'discount_amount' => 0,
        'tax_rate' => 10,
        'batch_number' => 'PURCHASE-BATCH',
        'expiry_date' => '2027-01-01',
        'serial_numbers' => ['PURCHASE-SERIAL-1', 'PURCHASE-SERIAL-2'],
    ]];
    $costs = [['description' => 'Freight', 'amount' => 20]];
    $create = fn (array $attributeOverrides = [], array $itemOverrides = [], array $costOverrides = []) => $service->createDraft(
        array_replace($attributes, $attributeOverrides),
        [array_replace($items[0], $itemOverrides)],
        'canonical-purchase-draft',
        array_replace($costs, $costOverrides),
    );

    $first = $create();
    $context['supplier']->update(['is_active' => false]);
    $context['supplierParty']->update(['is_active' => false]);
    $context['product']->update(['is_active' => false]);

    expect($create()->is($first))->toBeTrue()
        ->and(fn () => $create([], ['batch_number' => 'OTHER-BATCH']))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(fn () => $create([], ['serial_numbers' => ['OTHER-SERIAL']]))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(fn () => $create([], [], [['description' => 'Other freight', 'amount' => 20]]))->toThrow(DomainException::class, 'درخواست متفاوت');

    DB::table('invoices')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($create()->is($first))->toBeTrue()
        ->and(fn () => $create(['notes' => 'Changed']))->toThrow(DomainException::class, 'درخواست متفاوت');
});

it('canonicalizes transfer store creator and legacy persisted state', function () {
    $context = canonicalIdempotencyFoundation();
    $destination = Warehouse::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'code' => 'CANONICAL-DESTINATION',
        'name' => 'Canonical destination',
        'is_active' => true,
    ]);
    $otherStore = Store::query()->create([
        'code' => 'CANONICAL-OTHER-STORE',
        'name' => 'Canonical other store',
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 5,
        unitCost: 100,
        movementDate: $context['year']->starts_on->toDateString(),
        idempotencyKey: 'canonical-transfer-opening',
        movementType: InventoryMovementType::Opening,
    );
    $service = app(InventoryTransferService::class);
    $data = [
        'submission_token' => 'canonical-transfer-token',
        'source_warehouse_id' => $context['warehouse']->id,
        'destination_warehouse_id' => $destination->id,
        'items' => [['product_id' => $context['product']->id, 'quantity' => 2]],
        'transfer_date' => $context['year']->starts_on->toDateString(),
        'reference' => 'TRANSFER-REF',
        'notes' => 'Transfer notes',
    ];
    $transfer = fn (array $overrides = [], ?Store $store = null, ?User $creator = null) => $service->transfer(
        $store ?? $context['store'],
        array_replace($data, $overrides),
        $creator ?? $context['manager'],
    );
    $first = $transfer();
    $context['product']->update(['is_active' => false]);

    expect($transfer()->is($first))->toBeTrue()
        ->and(fn () => $transfer(creator: $context['otherUser']))->toThrow(DomainException::class)
        ->and(fn () => $transfer(store: $otherStore))->toThrow(DomainException::class)
        ->and(fn () => $transfer(['reference' => 'CHANGED']))->toThrow(DomainException::class);

    DB::table('inventory_transfers')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($transfer()->is($first))->toBeTrue()
        ->and(fn () => $transfer(creator: $context['otherUser']))->toThrow(DomainException::class)
        ->and(fn () => $transfer(store: $otherStore))->toThrow(DomainException::class)
        ->and(InventoryTransfer::query()->count())->toBe(1);
});

it('updates stock count hashes on draft edits and ignores costs the actor cannot view', function () {
    $context = canonicalIdempotencyFoundation();
    $service = app(StockCountService::class);
    $data = [
        'submission_token' => 'canonical-count-token',
        'warehouse_id' => $context['warehouse']->id,
        'count_date' => $context['year']->starts_on->toDateString(),
        'notes' => 'Count notes',
        'items' => [[
            'product_id' => $context['product']->id,
            'counted_quantity' => 4,
            'unit_cost' => 125,
        ]],
    ];
    $create = fn (array $overrides = []) => $service->createDraft(
        $context['store'],
        array_replace($data, $overrides),
        $context['manager'],
    );
    $first = $create();

    expect($create()->is($first))->toBeTrue()
        ->and(fn () => $create(['notes' => 'Changed']))->toThrow(DomainException::class)
        ->and(fn () => $create(['items' => [array_replace($data['items'][0], ['unit_cost' => 130])]]))->toThrow(DomainException::class);

    $updatedData = array_replace($data, [
        'submission_token' => 'canonical-count-update-token',
        'items' => [array_replace($data['items'][0], ['counted_quantity' => 3, 'unit_cost' => 130])],
    ]);
    $updated = $service->updateDraft($context['store'], $first, $updatedData, $context['manager']);
    $oldHash = $first->idempotency_request_hash;
    $currentData = array_replace($updatedData, ['submission_token' => $data['submission_token']]);

    expect($updated->idempotency_request_hash)->not->toBe($oldHash)
        ->and(fn () => $create())->toThrow(DomainException::class)
        ->and($service->createDraft($context['store'], $currentData, $context['manager'])->is($updated))->toBeTrue();

    $warehouseUser = User::factory()->create(['role' => UserRole::Warehouse]);
    $warehouseData = array_replace($data, [
        'submission_token' => 'canonical-warehouse-count',
        'items' => [array_replace($data['items'][0], ['unit_cost' => 111])],
    ]);
    $warehouseCount = $service->createDraft($context['store'], $warehouseData, $warehouseUser);
    $warehouseData['items'][0]['unit_cost'] = 999;

    expect($service->createDraft($context['store'], $warehouseData, $warehouseUser)->is($warehouseCount))->toBeTrue()
        ->and($warehouseCount->items()->firstOrFail()->unit_cost)->toBeNull();

    DB::table('stock_counts')->where('id', $updated->id)->update(['idempotency_request_hash' => null]);
    $context['product']->update(['is_active' => false]);

    expect($service->createDraft($context['store'], $currentData, $context['manager'])->is($updated))->toBeTrue()
        ->and(fn () => $service->createDraft(
            $context['store'],
            array_replace($currentData, ['notes' => 'Changed']),
            $context['manager'],
        ))->toThrow(DomainException::class);
});

it('includes the opening creator in the canonical projection before revalidation', function () {
    $context = canonicalIdempotencyFoundation();
    $service = app(OpeningInventoryService::class);
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => 2,
        'unit_cost' => 100,
    ]];
    $open = fn (User $actor) => $service->open(
        $context['branch'],
        $context['warehouse'],
        $context['year'],
        $context['year']->starts_on->toDateString(),
        $items,
        $actor,
        'canonical-opening',
    );
    $first = $open($context['manager']);
    $context['product']->update(['is_active' => false]);
    $context['branch']->update(['is_active' => false]);
    $context['warehouse']->update(['is_active' => false]);

    expect($open($context['manager'])->is($first))->toBeTrue()
        ->and(fn () => $open($context['otherUser']))->toThrow(DomainException::class, 'درخواست متفاوت')
        ->and(InventoryOpening::query()->count())->toBe(1);
});

it('keeps canonical idempotency columns on migration rollback', function () {
    $migration = require database_path('migrations/2026_11_26_000003_add_canonical_idempotency_hashes.php');
    $migration->down();

    expect(Schema::hasColumn('inventory_movements', 'idempotency_request_hash'))->toBeTrue()
        ->and(Schema::hasColumn('pos_sessions', 'close_request_hash'))->toBeTrue()
        ->and(Schema::hasColumn('pos_tenders', 'resolution_request_hash'))->toBeTrue();
});
