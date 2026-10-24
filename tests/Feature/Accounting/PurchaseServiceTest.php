<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseAdditionalCost;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Purchasing\PurchaseService;
use Database\Seeders\AccountSeeder;
use Illuminate\Auth\Access\AuthorizationException;

function createPurchaseContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_default', true)->firstOrFail();
    $fiscalYear = app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'SUP-PARTY-001',
        'name' => 'تأمین‌کننده آزمایشی',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
        'payment_terms' => '30 روز',
    ]);

    return compact('store', 'branch', 'warehouse', 'fiscalYear', 'party', 'supplier');
}

function purchaseUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('confirms a purchase invoice into inventory and accounting atomically', function () {
    $context = createPurchaseContext();
    $manager = purchaseUser(UserRole::Manager);
    $product = Product::query()->create([
        'code' => 'PURCHASE-P-001',
        'name' => 'کالای خرید',
        'unit' => 'عدد',
        'purchase_price' => 100_000,
        'sale_price' => 150_000,
        'tax_rate' => 10,
    ]);
    $service = app(PurchaseService::class);

    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
            'due_date' => '2026-05-31',
            'created_by' => $manager->id,
        ],
        [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_price' => 100_000,
                'tax_rate' => 10,
            ],
        ],
    );

    expect($invoice->type)->toBe(InvoiceType::Purchase)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->subtotal)->toBe('1000000.00')
        ->and($invoice->tax_total)->toBe('100000.00')
        ->and($invoice->total)->toBe('1100000.00');

    $confirmed = $service->confirm($invoice, $context['warehouse']->id, $manager);
    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $product->id)
        ->firstOrFail();
    $movement = InventoryMovement::query()->firstOrFail();
    $journal = JournalEntry::query()->with('lines.account')->firstOrFail();
    $payableLine = $journal->lines->first(
        fn ($line): bool => $line->account->code === '2101',
    );

    expect($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and($balance->quantity)->toBe('10.000')
        ->and($balance->inventory_value)->toBe('1000000.00')
        ->and($movement->movement_type)->toBe(InventoryMovementType::Purchase)
        ->and($movement->source_type)->toBe(Invoice::class)
        ->and($movement->source_id)->toBe($confirmed->id)
        ->and($journal->status->value)->toBe('posted')
        ->and($journal->source_type)->toBe(Invoice::class)
        ->and($journal->source_id)->toBe($confirmed->id)
        ->and($journal->totalDebit())->toBe('1100000.00')
        ->and($journal->totalCredit())->toBe('1100000.00')
        ->and($payableLine?->party_id)->toBe($context['party']->id)
        ->and(Account::query()->where('code', '2101')->first()->requires_party)->toBeTrue();
});

it('rolls back inventory when an unauthorized user confirms a purchase', function () {
    $context = createPurchaseContext();
    $cashier = purchaseUser(UserRole::Cashier);
    $product = Product::query()->create([
        'code' => 'PURCHASE-P-002',
        'name' => 'کالای خرید دوم',
        'purchase_price' => 50_000,
        'sale_price' => 80_000,
    ]);
    $service = app(PurchaseService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
            'created_by' => $cashier->id,
        ],
        [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 50_000,
            ],
        ],
    );

    expect(fn () => $service->confirm($invoice, $context['warehouse']->id, $cashier))
        ->toThrow(AuthorizationException::class);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('rejects receiving a purchase into another store warehouse', function () {
    $context = createPurchaseContext();
    $manager = purchaseUser(UserRole::Manager);
    $product = Product::query()->create([
        'code' => 'PURCHASE-WRONG-WH',
        'name' => 'کالای انبار اشتباه',
        'purchase_price' => 10_000,
    ]);
    $service = app(PurchaseService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
        ],
        [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10_000]],
    );
    $otherStore = Store::query()->create([
        'code' => 'OTHER-INVENTORY-STORE',
        'name' => 'فروشگاه انبار اشتباه',
    ]);
    $otherBranch = $otherStore->branches()->create([
        'code' => 'OB',
        'name' => 'شعبه اشتباه',
    ]);
    $wrongWarehouse = Warehouse::query()->create([
        'store_id' => $otherStore->id,
        'branch_id' => $otherBranch->id,
        'code' => 'WRONG',
        'name' => 'انبار اشتباه',
    ]);

    expect(fn () => $service->confirm($invoice, $wrongWarehouse->id, $manager))
        ->toThrow(DomainException::class)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('rejects negative discounts and tax rates outside zero to one hundred', function () {
    $context = createPurchaseContext();
    $product = Product::query()->create([
        'code' => 'PURCHASE-INVALID-AMOUNT',
        'name' => 'کالای محاسبات نامعتبر',
        'purchase_price' => 100_000,
    ]);
    $service = app(PurchaseService::class);

    expect(fn () => $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100_000,
            'discount_amount' => -10_000,
        ]],
    ))->toThrow(DomainException::class);

    expect(fn () => $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100_000,
            'tax_rate' => 101,
        ]],
    ))->toThrow(DomainException::class);
});

it('capitalizes additional purchase costs into inventory by item value', function () {
    $context = createPurchaseContext();
    $manager = purchaseUser(UserRole::Manager);
    $first = Product::query()->create([
        'code' => 'PURCHASE-FREIGHT-A',
        'name' => 'کالای حمل اول',
        'purchase_price' => 100,
        'sale_price' => 150,
    ]);
    $second = Product::query()->create([
        'code' => 'PURCHASE-FREIGHT-B',
        'name' => 'کالای حمل دوم',
        'purchase_price' => 200,
        'sale_price' => 300,
    ]);

    $invoice = app(PurchaseService::class)->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
            'created_by' => $manager->id,
        ],
        [
            [
                'product_id' => $first->id,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
            ],
            [
                'product_id' => $second->id,
                'quantity' => 1,
                'unit_price' => 200,
                'tax_rate' => 0,
            ],
        ],
        null,
        [
            ['description' => 'هزینه حمل', 'amount' => 60],
        ],
    );

    expect($invoice->additional_cost_total)->toBe('60.00')
        ->and($invoice->total)->toBe('360.00')
        ->and($invoice->additionalCosts)->toHaveCount(1)
        ->and(PurchaseAdditionalCost::query()->count())->toBe(1);

    $confirmed = app(PurchaseService::class)->confirm(
        $invoice,
        $context['warehouse']->id,
        $manager,
    );
    $firstMovement = InventoryMovement::query()
        ->where('source_id', $confirmed->id)
        ->where('product_id', $first->id)
        ->firstOrFail();
    $secondMovement = InventoryMovement::query()
        ->where('source_id', $confirmed->id)
        ->where('product_id', $second->id)
        ->firstOrFail();
    $journal = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $confirmed->id)
        ->with('lines.account')
        ->firstOrFail();
    $inventoryLine = $journal->lines->first(
        fn ($line): bool => $line->account->code === '1104',
    );

    expect($firstMovement->total_cost)->toBe('120.00')
        ->and($secondMovement->total_cost)->toBe('240.00')
        ->and($inventoryLine->debit)->toBe('360.00')
        ->and($journal->totalDebit())->toBe('360.00')
        ->and($journal->totalCredit())->toBe('360.00');
});
