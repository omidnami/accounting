<?php

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;

function nonStockItemsContext(): array
{
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'NONSTOCK-CUSTOMER',
        'name' => 'Customer',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'NONSTOCK-SUPPLIER',
        'name' => 'Supplier',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $stock = Product::query()->create([
        'code' => 'NONSTOCK-STOCK',
        'name' => 'Stock item',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
        'item_type' => 'stock',
    ]);
    $service = Product::query()->create([
        'code' => 'NONSTOCK-SERVICE',
        'name' => 'Service',
        'unit' => 'پروژه',
        'purchase_price' => 100,
        'sale_price' => 500,
        'tax_rate' => 10,
        'item_type' => 'service',
    ]);
    $nonStock = Product::query()->create([
        'code' => 'NONSTOCK-NONSTOCK',
        'name' => 'Non-stock item',
        'purchase_price' => 50,
        'sale_price' => 80,
        'tax_rate' => 0,
        'item_type' => 'non_stock',
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $stock->id,
        quantity: 10,
        unitCost: 100,
        movementDate: $year->starts_on->toDateString(),
        idempotencyKey: 'nonstock-opening',
        movementType: InventoryMovementType::Opening,
    );

    return compact(
        'store',
        'branch',
        'warehouse',
        'year',
        'manager',
        'customer',
        'supplier',
        'stock',
        'service',
        'nonStock',
    );
}

it('posts mixed stock and service sales without service movements or COGS', function () {
    $context = nonStockItemsContext();
    $movementsBefore = InventoryMovement::query()->count();
    $sales = app(SalesService::class);
    $invoice = $sales->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $context['year']->starts_on->toDateString(),
            'created_by' => $context['manager']->id,
        ],
        [
            [
                'product_id' => $context['stock']->id,
                'quantity' => 1,
                'unit_price' => 200,
                'discount_amount' => 0,
                'tax_rate' => 10,
            ],
            [
                'product_id' => $context['service']->id,
                'quantity' => 2,
                'unit_price' => 500,
                'discount_amount' => 0,
                'tax_rate' => 10,
            ],
        ],
        'mixed-sale',
    );
    $sales->confirm($invoice, $context['warehouse']->id, $context['manager']);

    $cogs = Account::query()->where('store_id', $context['store']->id)->where('code', '5101')->firstOrFail();
    $entry = JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->firstOrFail();
    expect(InventoryMovement::query()->count())->toBe($movementsBefore + 1)
        ->and($invoice->refresh()->total)->toBe('1320.00')
        ->and($invoice->items()->where('product_id', $context['service']->id)->firstOrFail()->total_cost_snapshot)->toBe('0.00')
        ->and($entry->lines()->where('account_id', $cogs->id)->firstOrFail()->debit)->toBe('100.00');
});

it('expenses service and non-stock purchases without inventory movements', function () {
    $context = nonStockItemsContext();
    $movementsBefore = InventoryMovement::query()->count();
    $purchases = app(PurchaseService::class);
    $invoice = $purchases->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => $context['year']->starts_on->toDateString(),
            'created_by' => $context['manager']->id,
        ],
        [
            [
                'product_id' => $context['service']->id,
                'quantity' => 2,
                'unit_price' => 100,
                'discount_amount' => 0,
                'tax_rate' => 0,
            ],
            [
                'product_id' => $context['nonStock']->id,
                'quantity' => 3,
                'unit_price' => 50,
                'discount_amount' => 0,
                'tax_rate' => 0,
            ],
        ],
        'nonstock-purchase',
    );
    $purchases->confirm($invoice, $context['warehouse']->id, $context['manager']);

    $expense = Account::query()->where('store_id', $context['store']->id)->where('code', '5108')->firstOrFail();
    $inventory = Account::query()->where('store_id', $context['store']->id)->where('code', '1104')->firstOrFail();
    $entry = JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->firstOrFail();
    expect(InventoryMovement::query()->count())->toBe($movementsBefore)
        ->and($invoice->refresh()->total)->toBe('350.00')
        ->and($entry->lines()->where('account_id', $expense->id)->firstOrFail()->debit)->toBe('350.00')
        ->and($entry->lines()->where('account_id', $inventory->id)->count())->toBe(0);
});

it('reverses service purchase expense on return without touching inventory', function () {
    $context = nonStockItemsContext();
    $purchases = app(PurchaseService::class);
    $invoice = $purchases->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => $context['year']->starts_on->toDateString(),
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['service']->id,
            'quantity' => 2,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'service-purchase-for-return',
    );
    $purchases->confirm($invoice, $context['warehouse']->id, $context['manager']);
    $movementsBefore = InventoryMovement::query()->count();
    $returns = app(PurchaseReturnService::class);
    $return = $returns->createDraft(
        $invoice,
        [[
            'invoice_item_id' => $invoice->items()->firstOrFail()->id,
            'quantity' => 2,
        ]],
        $context['year']->starts_on->toDateString(),
        $context['manager']->id,
        null,
        'service-purchase-return',
    );
    $returns->confirm($return, $context['warehouse']->id, $context['manager']);

    $expense = Account::query()->where('store_id', $context['store']->id)->where('code', '5108')->firstOrFail();
    $entry = JournalEntry::query()->where('source_type', $return->getMorphClass())->where('source_id', $return->id)->firstOrFail();
    expect(InventoryMovement::query()->count())->toBe($movementsBefore)
        ->and($entry->lines()->where('account_id', $expense->id)->firstOrFail()->credit)->toBe('200.00');
});

it('rejects direct inventory movements for service and non-stock products', function () {
    $context = nonStockItemsContext();

    expect(fn () => app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['service']->id,
        quantity: 1,
        unitCost: 100,
        movementDate: $context['year']->starts_on->toDateString(),
        idempotencyKey: 'invalid-service-movement',
    ))->toThrow(DomainException::class, 'حرکت انبار ندارند');
});
