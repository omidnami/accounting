<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Accounting\PeriodClosingService;
use App\Services\Purchasing\PurchaseService;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function purchaseUiContext(UserRole $role = UserRole::Manager): array
{
    app(AccountSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear(
            $store,
            app(JalaliDateService::class)->currentYear(),
        );
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'UI-SUPPLIER',
        'name' => 'تأمین‌کننده رابط خرید',
        'phone' => '09121234567',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
        'payment_terms' => '۳۰ روزه',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'UI-PURCHASE-PRODUCT',
        'barcode' => '626888888888',
        'name' => 'کالای خرید رابط',
        'unit' => 'عدد',
        'purchase_price' => 200,
        'sale_price' => 300,
        'tax_rate' => 10,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'party',
        'supplier',
        'product',
        'user',
    ) + ['date' => $fiscalYear->starts_on->toDateString()];
}

/**
 * @return array<string, mixed>
 */
function purchaseUiPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'submission_token' => fake()->uuid(),
        'supplier_id' => $context['supplier']->id,
        'invoice_date' => $context['date'],
        'due_date' => null,
        'notes' => 'خرید آزمایشی رابط',
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity' => '2',
            'unit_price' => '200',
            'discount_amount' => '0',
            'tax_rate' => '10',
        ]],
    ], $overrides);
}

it('protects purchase pages by role', function () {
    $this->get(route('purchases.index'))->assertRedirect(route('login'));
    $cashier = purchaseUiContext(UserRole::Cashier)['user'];

    $this->actingAs($cashier)
        ->get(route('purchases.index'))
        ->assertForbidden();
});

it('allows managers and accountants into purchasing', function (UserRole $role) {
    $context = purchaseUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('purchases.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchases/Create')
            ->has('warehouses', 1)
            ->where('defaults.warehouse_id', $context['warehouse']->id));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('rejects a purchase draft dated inside a closed accounting period', function () {
    $context = purchaseUiContext(UserRole::Manager);
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
        ->post(route('purchases.store'), purchaseUiPayload($context))
        ->assertSessionHasErrors('purchase');

    expect(Invoice::query()->count())->toBe(0);
});

it('looks up active suppliers and products within the selected warehouse', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->getJson(route('purchases.lookups.suppliers', ['search' => 'رابط']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $context['supplier']->id);

    $this->actingAs($context['user'])
        ->getJson(route('purchases.lookups.products', [
            'warehouse_id' => $context['warehouse']->id,
            'search' => '۶۲۶۸۸۸',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $context['product']->id)
        ->assertJsonPath('data.0.stock_quantity', '0.000');
});

it('creates a purchase draft and handles repeated submissions idempotently', function () {
    $context = purchaseUiContext();
    $payload = purchaseUiPayload($context);

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), $payload)
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), $payload)
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();

    expect(Invoice::query()->count())->toBe(1)
        ->and($invoice->type)->toBe(InvoiceType::Purchase)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->subtotal)->toBe('400.00')
        ->and($invoice->tax_total)->toBe('40.00')
        ->and($invoice->total)->toBe('440.00')
        ->and($invoice->idempotency_key)->toBe($payload['submission_token']);

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), array_replace($payload, [
            'items' => [[
                'product_id' => $context['product']->id,
                'quantity' => '3',
                'unit_price' => '200',
                'discount_amount' => '0',
                'tax_rate' => '10',
            ]],
        ]))
        ->assertSessionHasErrors('purchase');
});

it('confirms a purchase through the interface and receives stock atomically', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context))
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('purchases.confirm', $invoice), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertRedirect(route('purchases.show', $invoice));

    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $context['product']->id)
        ->firstOrFail();
    $entry = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $invoice->id)
        ->firstOrFail();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and($balance->quantity)->toBe('2.000')
        ->and($balance->inventory_value)->toBe('400.00')
        ->and($entry->totalDebit())->toBe('440.00')
        ->and($entry->totalCredit())->toBe('440.00');
});

it('rejects confirmation when the supplier is deactivated after drafting', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context))
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();
    $context['supplier']->update(['is_active' => false]);

    $this->actingAs($context['user'])
        ->post(route('purchases.confirm', $invoice), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertSessionHasErrors('purchase');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and(InventoryBalance::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('validates line errors and deletes drafts only', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context, [
            'items' => [[
                'product_id' => $context['product']->id,
                'quantity' => '0',
                'unit_price' => '0',
                'tax_rate' => '120',
            ]],
        ]))
        ->assertSessionHasErrors([
            'items.0.quantity',
            'items.0.unit_price',
            'items.0.tax_rate',
        ]);

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context));

    $invoice = Invoice::query()->firstOrFail();
    $this->actingAs($context['user'])
        ->delete(route('purchases.destroy', $invoice))
        ->assertRedirect(route('purchases.index'));

    expect(Invoice::query()->count())->toBe(0);

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context));

    $confirmed = Invoice::query()->firstOrFail();
    $this->actingAs($context['user'])
        ->post(route('purchases.confirm', $confirmed), [
            'warehouse_id' => $context['warehouse']->id,
        ]);

    $this->actingAs($context['user'])
        ->delete(route('purchases.destroy', $confirmed))
        ->assertSessionHasErrors('purchase');

    expect($confirmed->refresh()->status)->toBe(InvoiceStatus::Confirmed);
});

it('renders the purchase index', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context));

    $this->actingAs($context['user'])
        ->get(route('purchases.index', ['status' => 'draft']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchases/Index')
            ->where('summary.total', 1)
            ->where('summary.draft', 1)
            ->has('invoices.data', 1));
});

it('renders a populated purchase edit form and updates the draft through the interface', function () {
    $context = purchaseUiContext();
    $createPayload = purchaseUiPayload($context, [
        'additional_costs' => [
            ['description' => 'حمل اولیه', 'amount' => '30'],
        ],
    ]);

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), $createPayload)
        ->assertRedirect();

    $invoice = Invoice::query()->firstOrFail();
    $originalHash = $invoice->idempotency_request_hash;

    $this->actingAs($context['user'])
        ->get(route('purchases.edit', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchases/Create')
            ->where('invoice.id', $invoice->id)
            ->where('invoice.invoice_number', $invoice->invoice_number)
            ->where('invoice.supplier.id', $context['supplier']->id)
            ->where('invoice.items.0.product_id', $context['product']->id)
            ->where('invoice.items.0.quantity', '2.000')
            ->where('invoice.additional_costs.0.description', 'حمل اولیه')
            ->where('defaults.invoice_date', $context['date']));

    $updatePayload = purchaseUiPayload($context, [
        'notes' => 'خرید ویرایش‌شده رابط',
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity' => '3',
            'unit_price' => '250',
            'discount_amount' => '10',
            'tax_rate' => '10',
        ]],
        'additional_costs' => [
            ['description' => 'حمل ویرایش‌شده', 'amount' => '30'],
        ],
    ]);
    unset($updatePayload['submission_token']);

    $this->actingAs($context['user'])
        ->put(route('purchases.update', $invoice), $updatePayload)
        ->assertRedirect(route('purchases.show', $invoice));

    $updated = $invoice->refresh();

    expect($updated->id)->toBe($invoice->id)
        ->and($updated->invoice_number)->toBe($invoice->invoice_number)
        ->and($updated->idempotency_key)->toBe($createPayload['submission_token'])
        ->and($updated->idempotency_request_hash)->not->toBe($originalHash)
        ->and($updated->status)->toBe(InvoiceStatus::Draft)
        ->and($updated->notes)->toBe('خرید ویرایش‌شده رابط')
        ->and($updated->subtotal)->toBe('750.00')
        ->and($updated->discount_total)->toBe('10.00')
        ->and($updated->tax_total)->toBe('74.00')
        ->and($updated->additional_cost_total)->toBe('30.00')
        ->and($updated->total)->toBe('844.00')
        ->and($updated->items)->toHaveCount(1)
        ->and($updated->items->first()->quantity)->toBe('3.000')
        ->and($updated->additionalCosts)->toHaveCount(1)
        ->and($updated->additionalCosts->first()->description)->toBe('حمل ویرایش‌شده')
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('rejects purchase edit and update routes for confirmed invoices', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context));

    $invoice = Invoice::query()->firstOrFail();
    $this->actingAs($context['user'])
        ->post(route('purchases.confirm', $invoice), [
            'warehouse_id' => $context['warehouse']->id,
        ]);

    $updatePayload = purchaseUiPayload($context);
    unset($updatePayload['submission_token']);

    $this->actingAs($context['user'])
        ->get(route('purchases.edit', $invoice))
        ->assertNotFound();
    $this->actingAs($context['user'])
        ->put(route('purchases.update', $invoice), $updatePayload)
        ->assertNotFound();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and($invoice->refresh()->total)->toBe('440.00')
        ->and(InventoryMovement::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('rejects purchase edit and update routes outside the active scope or invoice type', function () {
    $context = purchaseUiContext();
    $otherStore = Store::query()->create([
        'code' => 'OTHER-PURCHASE-EDIT-STORE',
        'name' => 'فروشگاه خارج از scope',
    ]);
    $otherBranch = $otherStore->branches()->create([
        'code' => 'OTHER-EDIT-BRANCH',
        'name' => 'شعبه خارج از scope',
    ]);
    $otherYear = app(FiscalYearSetupService::class)->setupJalaliYear($otherStore, 1405);
    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'OTHER-EDIT-SUPPLIER',
        'name' => 'تأمین‌کننده خارج از scope',
    ]);
    $otherSupplier = Supplier::query()->create(['party_id' => $otherParty->id]);
    $foreignInvoice = app(PurchaseService::class)->createDraft(
        [
            'store_id' => $otherStore->id,
            'branch_id' => $otherBranch->id,
            'fiscal_year_id' => $otherYear->id,
            'supplier_id' => $otherSupplier->id,
            'invoice_date' => $otherYear->starts_on->toDateString(),
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
    $customerParty = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'PURCHASE-EDIT-CUSTOMER',
        'name' => 'مشتری آزمون scope خرید',
    ]);
    $customer = Customer::query()->create(['party_id' => $customerParty->id]);
    $salesInvoice = Invoice::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'invoice_number' => 'SALES-PURCHASE-EDIT-SCOPE',
        'type' => InvoiceType::Sales,
        'invoice_date' => $context['date'],
        'customer_id' => $customer->id,
        'supplier_id' => null,
    ]);
    $updatePayload = purchaseUiPayload($context);
    unset($updatePayload['submission_token']);

    foreach ([$foreignInvoice, $salesInvoice] as $invoice) {
        $this->actingAs($context['user'])
            ->get(route('purchases.edit', $invoice))
            ->assertNotFound();
        $this->actingAs($context['user'])
            ->put(route('purchases.update', $invoice), $updatePayload)
            ->assertNotFound();
    }

    expect($foreignInvoice->fresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($foreignInvoice->fresh()->total)->toBe('220.00')
        ->and($salesInvoice->fresh()->type)->toBe(InvoiceType::Sales);
});

it('converts purchase draft update service failures into validation errors', function () {
    $context = purchaseUiContext();

    $this->actingAs($context['user'])
        ->post(route('purchases.store'), purchaseUiPayload($context));

    $invoice = Invoice::query()->firstOrFail();
    $context['supplier']->update(['is_active' => false]);
    $updatePayload = purchaseUiPayload($context, [
        'notes' => 'نباید ذخیره شود',
    ]);
    unset($updatePayload['submission_token']);

    $this->actingAs($context['user'])
        ->put(route('purchases.update', $invoice), $updatePayload)
        ->assertSessionHasErrors('purchase');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->refresh()->notes)->toBe('خرید آزمایشی رابط')
        ->and($invoice->refresh()->total)->toBe('440.00')
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});
