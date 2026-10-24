<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\PeriodClosingService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function salesUiContext(UserRole $role = UserRole::Manager): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'UI-CUSTOMER',
        'name' => 'مشتری رابط فروش',
        'phone' => '09121234567',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'UI-PRODUCT',
        'barcode' => '626777777777',
        'name' => 'کالای رابط فروش',
        'unit' => 'عدد',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
        'reorder_level' => 2,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $date = $fiscalYear->starts_on->toDateString();

    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 100,
        movementDate: $date,
        idempotencyKey: 'sales-ui-opening-'.$user->id,
        movementType: InventoryMovementType::Opening,
    );

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'party',
        'customer',
        'product',
        'user',
        'date',
    );
}

/**
 * @return array<string, mixed>
 */
function salesInvoicePayload(array $context, array $overrides = []): array
{
    return array_replace([
        'submission_token' => fake()->uuid(),
        'customer_id' => $context['customer']->id,
        'invoice_date' => $context['date'],
        'due_date' => null,
        'notes' => 'فاکتور آزمایشی رابط فروش',
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity' => '2',
            'unit_price' => '200',
            'discount_amount' => '0',
            'tax_rate' => '10',
        ]],
    ], $overrides);
}

it('protects sales pages by role', function () {
    $this->get(route('sales.index'))->assertRedirect(route('login'));

    $warehouseUser = salesUiContext(UserRole::Warehouse)['user'];

    $this->actingAs($warehouseUser)
        ->get(route('sales.index'))
        ->assertForbidden();

    $this->actingAs($warehouseUser)
        ->get(route('sales.lookups.customers'))
        ->assertForbidden();
});

it('allows sales roles into the checkout', function (UserRole $role) {
    $context = salesUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('sales.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Create')
            ->has('warehouses', 1)
            ->where('defaults.warehouse_id', $context['warehouse']->id)
            ->where('defaults.fiscal_year.title', $context['fiscalYear']->title));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
    'cashier' => [UserRole::Cashier],
]);

it('rejects a sales draft dated inside a closed accounting period', function () {
    $context = salesUiContext(UserRole::Manager);
    $period = AccountingPeriod::query()
        ->where('fiscal_year_id', $context['fiscalYear']->id)
        ->whereDate('starts_on', '<=', $context['date'])
        ->whereDate('ends_on', '>=', $context['date'])
        ->firstOrFail();
    app(PeriodClosingService::class)->closePeriod(
        $context['store'],
        $period,
        AccountingPeriodStatus::Closed,
        $context['user'],
        'بستن دوره برای آزمون جلوگیری از پیش‌نویس',
    );

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context))
        ->assertSessionHasErrors('sale');

    expect(Invoice::query()->count())->toBe(0);
});

it('looks up active customers inside the current store', function () {
    $context = salesUiContext();
    $otherStore = Store::query()->create([
        'code' => 'OTHER-SALES-UI',
        'name' => 'فروشگاه دیگر',
        'is_active' => true,
    ]);
    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'OTHER-CUSTOMER',
        'name' => 'مشتری فروشگاه دیگر',
        'is_active' => true,
    ]);
    Customer::query()->create([
        'party_id' => $otherParty->id,
        'is_active' => true,
    ]);

    $this->actingAs($context['user'])
        ->getJson(route('sales.lookups.customers', ['search' => 'رابط']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $context['customer']->id)
        ->assertJsonPath('data.0.code', 'UI-CUSTOMER');
});

it('looks up products with stock scoped to the selected warehouse', function () {
    $context = salesUiContext();

    $this->actingAs($context['user'])
        ->getJson(route('sales.lookups.products', [
            'warehouse_id' => $context['warehouse']->id,
            'search' => '۶۲۶۷۷۷',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $context['product']->id)
        ->assertJsonPath('data.0.stock_quantity', '10.000')
        ->assertJsonPath('data.0.sale_price', '200.00');
});

it('creates a sales draft and makes repeated submission idempotent', function () {
    $context = salesUiContext();
    $payload = salesInvoicePayload($context);

    $this->actingAs($context['user'])
        ->post(route('sales.store'), $payload)
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), $payload)
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();

    expect(Invoice::query()->count())->toBe(1)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->subtotal)->toBe('400.00')
        ->and($invoice->tax_total)->toBe('40.00')
        ->and($invoice->total)->toBe('440.00')
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->idempotency_key)->toBe($payload['submission_token']);

    $this->actingAs($context['user'])
        ->post(route('sales.store'), array_replace($payload, [
            'items' => [[
                'product_id' => $context['product']->id,
                'quantity' => '3',
                'unit_price' => '200',
                'discount_amount' => '0',
                'tax_rate' => '10',
            ]],
        ]))
        ->assertSessionHasErrors('sale');

    expect(Invoice::query()->count())->toBe(1);
});

it('reports item-level validation errors for invalid sales lines', function () {
    $context = salesUiContext();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context, [
            'items' => [[
                'product_id' => $context['product']->id,
                'quantity' => '0',
                'unit_price' => '200',
                'discount_amount' => '0',
                'tax_rate' => '120',
            ]],
        ]))
        ->assertSessionHasErrors([
            'items.0.quantity',
            'items.0.tax_rate',
        ]);
});

it('lets cashiers create drafts but requires a manager to confirm them', function () {
    $context = salesUiContext(UserRole::Cashier);
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context))
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();
    $context['branch']->update([
        'invoice_print_format' => 'a5',
        'invoice_header_note' => 'متن سربرگ آزمایشی',
        'thermal_paper_width' => 80,
        'thermal_copies' => 3,
    ]);

    $this->actingAs($context['user'])
        ->get(route('sales.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Show')
            ->where('seller.name', $context['store']->legal_name ?: $context['store']->name)
            ->where('seller.branch_name', $context['branch']->name)
            ->where('print_settings.format', 'a5')
            ->where('print_settings.copies', 3)
            ->where('print_settings.header_note', 'متن سربرگ آزمایشی')
            ->where('can_confirm', false));

    $this->actingAs($context['user'])
        ->post(route('sales.confirm', $invoice), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertForbidden();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);

    $this->actingAs($manager)
        ->get(route('sales.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Show')
            ->where('can_confirm', true));

    $this->actingAs($manager)
        ->post(route('sales.confirm', $invoice), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertRedirect(route('sales.show', $invoice));

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Confirmed);
});

it('confirms a draft through the interface and posts inventory and accounting atomically', function () {
    $context = salesUiContext();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context))
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('sales.confirm', $invoice), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertRedirect(route('sales.show', $invoice));

    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $context['product']->id)
        ->firstOrFail();
    $entry = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $invoice->id)
        ->firstOrFail();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and($balance->quantity)->toBe('8.000')
        ->and($entry->totalDebit())->toBe($entry->totalCredit())
        ->and($entry->totalDebit())->toBe('640.00');
});

it('rejects confirming an invoice twice', function () {
    $context = salesUiContext();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context));

    $invoice = Invoice::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('sales.confirm', $invoice), ['warehouse_id' => $context['warehouse']->id])
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('sales.confirm', $invoice), ['warehouse_id' => $context['warehouse']->id])
        ->assertSessionHasErrors('sale');
});

it('deletes only draft invoices', function () {
    $context = salesUiContext();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context));

    $draft = Invoice::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->delete(route('sales.destroy', $draft))
        ->assertRedirect(route('sales.index'));

    expect(Invoice::query()->count())->toBe(0);

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context));

    $invoice = Invoice::query()->firstOrFail();
    $this->actingAs($context['user'])
        ->post(route('sales.confirm', $invoice), ['warehouse_id' => $context['warehouse']->id]);

    $this->actingAs($context['user'])
        ->delete(route('sales.destroy', $invoice))
        ->assertSessionHasErrors('sale');
});

it('renders sales index and filters by status', function () {
    $context = salesUiContext();

    $this->actingAs($context['user'])
        ->post(route('sales.store'), salesInvoicePayload($context));

    $this->actingAs($context['user'])
        ->get(route('sales.index', ['status' => 'draft']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Index')
            ->where('summary.total', 1)
            ->where('summary.draft', 1)
            ->has('invoices.data', 1)
            ->where('invoices.data.0.status', 'draft'));
});
