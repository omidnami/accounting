<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\InventoryMovementType;
use App\Enums\StockCountStatus;
use App\Enums\UserRole;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\PeriodClosingService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Inventory\StockCountService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     store: Store,
 *     fiscal_year: FiscalYear,
 *     warehouse: Warehouse,
 *     destination: Warehouse,
 *     product: Product,
 *     second_product: Product
 * }
 */
function stockOperationsContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $fiscalYear = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->firstOrFail();
    $warehouse = $store->warehouses()->where('is_default', true)->firstOrFail();
    $destination = Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $warehouse->branch_id,
        'code' => 'STOCK-DEST',
        'name' => 'انبار مقصد شمارش',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'STOCK-P-001',
        'name' => 'کالای شمارش فیزیکی',
        'unit' => 'عدد',
        'purchase_price' => 100,
        'sale_price' => 150,
        'is_active' => true,
    ]);
    $secondProduct = Product::query()->create([
        'code' => 'STOCK-P-002',
        'barcode' => '626800000002',
        'name' => 'کالای دوم انتقال',
        'unit' => 'کارتن',
        'purchase_price' => 250,
        'sale_price' => 350,
        'is_active' => true,
    ]);

    return compact(
        'store',
        'fiscalYear',
        'warehouse',
        'destination',
        'product',
        'secondProduct',
    ) + [
        'fiscal_year' => $fiscalYear,
        'second_product' => $secondProduct,
    ];
}

function stockOperationsUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

/**
 * @return array<string, mixed>
 */
function stockCountPayload(
    array $context,
    float|int|string $counted,
    ?string $unitCost = null,
): array {
    return [
        'submission_token' => fake()->uuid(),
        'warehouse_id' => $context['warehouse']->id,
        'count_date' => $context['fiscal_year']->starts_on->toDateString(),
        'notes' => 'شمارش آزمایشی',
        'items' => [[
            'product_id' => $context['product']->id,
            'counted_quantity' => $counted,
            'unit_cost' => $unitCost,
        ]],
    ];
}

it('lets a warehouse prepare a count while a manager posts the shortage adjustment', function () {
    $context = stockOperationsContext();
    $warehouseUser = stockOperationsUser(UserRole::Warehouse);
    $manager = stockOperationsUser(UserRole::Manager);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 10,
        unitCost: 100,
        movementDate: $context['fiscal_year']->starts_on->toDateString(),
        idempotencyKey: 'stock-count-opening',
        movementType: InventoryMovementType::Opening,
    );
    $count = app(StockCountService::class)->createDraft(
        $context['store'],
        stockCountPayload($context, 7, '1'),
        $warehouseUser,
    );
    $warehouseDetail = app(StockCountService::class)->detail(
        $context['store'],
        $count,
        false,
    );

    expect($count->status)->toBe(StockCountStatus::Draft)
        ->and($count->items()->firstOrFail()->unit_cost)->toBeNull()
        ->and($warehouseDetail['items'][0])->not->toHaveKey('unit_cost')
        ->and($warehouseDetail['items'][0])->not->toHaveKey('adjustment_value');

    $posted = app(StockCountService::class)->postCount(
        $context['store'],
        $count,
        $manager,
    );
    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $context['product']->id)
        ->firstOrFail();
    $lossLine = JournalEntry::query()
        ->where('source_type', StockCount::class)
        ->where('source_id', $count->id)
        ->firstOrFail()
        ->lines()
        ->whereHas('account', fn ($query) => $query->where('code', '5107'))
        ->firstOrFail();

    expect($posted->status)->toBe(StockCountStatus::Posted)
        ->and($balance->quantity)->toBe('7.000')
        ->and($lossLine->debit)->toBe('300.00')
        ->and(InventoryMovement::query()
            ->where('source_type', StockCount::class)
            ->where('source_id', $count->id)
            ->where('movement_type', InventoryMovementType::Adjustment)
            ->count())->toBe(1);
});

it('posts a stock surplus with a controlled unit cost and inventory gain', function () {
    $context = stockOperationsContext();
    $manager = stockOperationsUser(UserRole::Manager);
    $count = app(StockCountService::class)->createDraft(
        $context['store'],
        stockCountPayload($context, 4, '125'),
        $manager,
    );
    app(StockCountService::class)->postCount($context['store'], $count, $manager);
    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $context['product']->id)
        ->firstOrFail();
    $gainLine = JournalEntry::query()
        ->where('source_type', StockCount::class)
        ->where('source_id', $count->id)
        ->firstOrFail()
        ->lines()
        ->whereHas('account', fn ($query) => $query->where('code', '4104'))
        ->firstOrFail();

    expect($balance->quantity)->toBe('4.000')
        ->and($balance->inventory_value)->toBe('500.00')
        ->and($gainLine->credit)->toBe('500.00');
});

it('blocks period closing while a stock count is still draft', function () {
    $context = stockOperationsContext();
    $manager = stockOperationsUser(UserRole::Manager);
    app(StockCountService::class)->createDraft(
        $context['store'],
        stockCountPayload($context, 0),
        $manager,
    );
    $period = AccountingPeriod::query()
        ->where('fiscal_year_id', $context['fiscal_year']->id)
        ->whereDate('starts_on', '<=', $context['fiscal_year']->starts_on)
        ->whereDate('ends_on', '>=', $context['fiscal_year']->starts_on)
        ->firstOrFail();

    expect(fn () => app(PeriodClosingService::class)->closePeriod(
        $context['store'],
        $period,
        AccountingPeriodStatus::Closed,
        $manager,
        'بستن دوره با شمارش باز',
    ))->toThrow(DomainException::class);
});

it('transfers inventory atomically and keeps costs hidden from warehouse listings', function () {
    $context = stockOperationsContext();
    $warehouseUser = stockOperationsUser(UserRole::Warehouse);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 10,
        unitCost: 100,
        movementDate: $context['fiscal_year']->starts_on->toDateString(),
        idempotencyKey: 'transfer-opening',
        movementType: InventoryMovementType::Opening,
    );
    $payload = [
        'submission_token' => fake()->uuid(),
        'source_warehouse_id' => $context['warehouse']->id,
        'destination_warehouse_id' => $context['destination']->id,
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity' => '4',
        ]],
        'transfer_date' => $context['fiscal_year']->starts_on->toDateString(),
        'reference' => 'TR-1',
        'notes' => 'انتقال آزمایشی',
    ];
    $transfer = app(InventoryTransferService::class)->transfer(
        $context['store'],
        $payload,
        $warehouseUser,
    );
    $source = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $context['product']->id)
        ->firstOrFail();
    $destinationBalance = InventoryBalance::query()
        ->where('warehouse_id', $context['destination']->id)
        ->where('product_id', $context['product']->id)
        ->firstOrFail();
    $warehouseRows = app(InventoryTransferService::class)->paginate(
        $context['store'],
        ['warehouse_id' => null],
        $context['warehouse']->branch_id,
        false,
    )->items();

    expect($transfer->unit_cost)->toBe('100.0000')
        ->and($transfer->total_cost)->toBe('400.00')
        ->and($source->quantity)->toBe('6.000')
        ->and($destinationBalance->quantity)->toBe('4.000')
        ->and($warehouseRows[0])->not->toHaveKey('total_cost')
        ->and(InventoryMovement::query()
            ->where('source_type', InventoryTransfer::class)
            ->where('source_id', $transfer->id)
            ->count())->toBe(2);

    $repeat = app(InventoryTransferService::class)->transfer(
        $context['store'],
        $payload,
        $warehouseUser,
    );

    expect($repeat->id)->toBe($transfer->id)
        ->and(InventoryTransfer::query()->count())->toBe(1);
});

it('transfers multiple products in one atomic document', function () {
    $context = stockOperationsContext();
    $warehouseUser = stockOperationsUser(UserRole::Warehouse);
    $inventory = app(InventoryService::class);
    $inventory->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 10,
        unitCost: 100,
        movementDate: $context['fiscal_year']->starts_on->toDateString(),
        idempotencyKey: 'multi-transfer-opening-first',
        movementType: InventoryMovementType::Opening,
    );
    $inventory->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['second_product']->id,
        quantity: 8,
        unitCost: 250,
        movementDate: $context['fiscal_year']->starts_on->toDateString(),
        idempotencyKey: 'multi-transfer-opening-second',
        movementType: InventoryMovementType::Opening,
    );

    $transfer = app(InventoryTransferService::class)->transfer(
        $context['store'],
        [
            'submission_token' => fake()->uuid(),
            'source_warehouse_id' => $context['warehouse']->id,
            'destination_warehouse_id' => $context['destination']->id,
            'items' => [
                [
                    'product_id' => $context['product']->id,
                    'quantity' => '3',
                ],
                [
                    'product_id' => $context['second_product']->id,
                    'quantity' => '2',
                ],
            ],
            'transfer_date' => $context['fiscal_year']->starts_on->toDateString(),
        ],
        $warehouseUser,
    );

    expect($transfer->items)->toHaveCount(2)
        ->and($transfer->quantity)->toBe('5.000')
        ->and($transfer->total_cost)->toBe('800.00')
        ->and(InventoryMovement::query()
            ->where('source_type', InventoryTransfer::class)
            ->where('source_id', $transfer->id)
            ->count())->toBe(4)
        ->and(InventoryBalance::query()
            ->where('warehouse_id', $context['destination']->id)
            ->where('product_id', $context['second_product']->id)
            ->value('quantity'))->toBe('2.000');
});

it('searches inventory products and imports a stock count CSV', function () {
    $context = stockOperationsContext();
    $manager = stockOperationsUser(UserRole::Manager);

    $this->actingAs($manager)
        ->getJson(route('inventory.lookups.products', [
            'warehouse_id' => $context['warehouse']->id,
            'search' => '۶۲۶۸۰۰۰۰۰۰۰۲',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $context['second_product']->id);

    $file = UploadedFile::fake()->createWithContent(
        'stock-count.csv',
        "code,quantity,unit_cost\n"
        .$context['product']->code.",3,100\n"
        .$context['second_product']->code.",2,250\n",
    );

    $this->actingAs($manager)
        ->postJson(route('stock-counts.import'), ['file' => $file])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.counted_quantity', '3.000')
        ->assertJsonPath('data.1.unit_cost', '250.0000');
});

it('renders stock operation pages and enforces operational roles', function () {
    $context = stockOperationsContext();
    $warehouseUser = stockOperationsUser(UserRole::Warehouse);
    $cashier = stockOperationsUser(UserRole::Cashier);

    $this->actingAs($warehouseUser)
        ->get(route('stock-counts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/counts/Index')
            ->where('can_post', false));

    $this->actingAs($warehouseUser)
        ->get(route('inventory-transfers.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/transfers/Create')
            ->where('can_view_costs', false));

    $this->actingAs($cashier)
        ->get(route('stock-counts.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->get(route('inventory-transfers.index'))
        ->assertForbidden();
});

it('does not let a warehouse user delete a count created by a manager', function () {
    $context = stockOperationsContext();
    $manager = stockOperationsUser(UserRole::Manager);
    $warehouseUser = stockOperationsUser(UserRole::Warehouse);
    $count = app(StockCountService::class)->createDraft(
        $context['store'],
        stockCountPayload($context, 0),
        $manager,
    );

    $this->actingAs($warehouseUser)
        ->delete(route('stock-counts.destroy', $count))
        ->assertSessionHasErrors('count');

    expect(StockCount::query()->whereKey($count->id)->exists())->toBeTrue();
});
