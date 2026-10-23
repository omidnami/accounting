<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function invoiceReturnUiContext(UserRole $role = UserRole::Manager): array
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
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'RETURN-UI-CUSTOMER',
        'name' => 'مشتری مرجوعی',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'RETURN-UI-SUPPLIER',
        'name' => 'تأمین‌کننده مرجوعی',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'RETURN-UI-PRODUCT',
        'name' => 'کالای مرجوعی',
        'purchase_price' => 200,
        'sale_price' => 300,
        'tax_rate' => 10,
        'is_active' => true,
    ]);
    $date = $fiscalYear->starts_on->toDateString();
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 20,
        unitCost: 200,
        movementDate: $date,
        idempotencyKey: 'return-ui-opening-'.$user->id,
        movementType: InventoryMovementType::Opening,
    );
    $sales = app(SalesService::class);
    $salesInvoice = $sales->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'customer_id' => $customer->id,
            'invoice_date' => $date,
            'created_by' => $user->id,
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 4,
            'unit_price' => 300,
            'discount_amount' => 40,
            'tax_rate' => 10,
        ]],
        'return-ui-sale-'.$user->id,
    );
    $salesInvoice = $sales->confirm($salesInvoice, $warehouse->id, $user);
    $purchases = app(PurchaseService::class);
    $purchaseInvoice = $purchases->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'supplier_id' => $supplier->id,
            'invoice_date' => $date,
            'created_by' => $user->id,
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 6,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 10,
        ]],
        'return-ui-purchase-'.$user->id,
    );
    $purchaseInvoice = $purchases->confirm(
        $purchaseInvoice,
        $warehouse->id,
        $user,
    );

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'user',
        'customer',
        'supplier',
        'product',
        'salesInvoice',
        'purchaseInvoice',
        'date',
    );
}

/**
 * @return array<string, mixed>
 */
function salesReturnUiPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'submission_token' => fake()->uuid(),
        'return_date' => $context['date'],
        'items' => [[
            'invoice_item_id' => $context['salesInvoice']->items()->firstOrFail()->id,
            'quantity' => '2',
        ]],
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function purchaseReturnUiPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'submission_token' => fake()->uuid(),
        'return_date' => $context['date'],
        'notes' => 'مرجوعی آزمایشی خرید',
        'items' => [[
            'invoice_item_id' => $context['purchaseInvoice']->items()->firstOrFail()->id,
            'quantity' => '2',
        ]],
    ], $overrides);
}

it('protects sales and purchase return interfaces by role', function () {
    $this->get(route('sales.returns.create', 1))
        ->assertRedirect(route('login'));

    $context = invoiceReturnUiContext();
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();

    $this->actingAs($cashier)
        ->get(route('sales.returns.create', $context['salesInvoice']))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->get(route('purchases.returns.create', $context['purchaseInvoice']))
        ->assertForbidden();
});

it('allows managers and accountants to open return forms', function (UserRole $role) {
    $context = invoiceReturnUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('sales.returns.create', $context['salesInvoice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('returns/Create')
            ->where('kind', 'sales')
            ->has('items', 1)
            ->where('items.0.returnable_quantity', '4.000'));

    $this->actingAs($context['user'])
        ->get(route('purchases.returns.create', $context['purchaseInvoice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('returns/Create')
            ->where('kind', 'purchase')
            ->has('items', 1));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('creates an idempotent sales return draft and confirms it', function () {
    $context = invoiceReturnUiContext();
    $payload = salesReturnUiPayload($context);

    $this->actingAs($context['user'])
        ->post(route('sales.returns.store', $context['salesInvoice']), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $return = Invoice::query()
        ->where('type', InvoiceType::SalesReturn)
        ->firstOrFail();
    $showUrl = route('sales.returns.show', $return);

    $this->actingAs($context['user'])
        ->post(route('sales.returns.store', $context['salesInvoice']), $payload)
        ->assertRedirect($showUrl);

    expect(Invoice::query()->where('type', InvoiceType::SalesReturn)->count())
        ->toBe(1);

    $this->actingAs($context['user'])
        ->post(route('sales.returns.store', $context['salesInvoice']), array_replace(
            $payload,
            ['items' => [[
                'invoice_item_id' => $context['salesInvoice']->items()->firstOrFail()->id,
                'quantity' => '3',
            ]]],
        ))
        ->assertSessionHasErrors('return');

    $this->actingAs($context['user'])
        ->get($showUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('returns/Show')
            ->where('return_invoice.status', 'draft')
            ->where('parent.id', $context['salesInvoice']->id));

    $this->actingAs($context['user'])
        ->post(route('sales.returns.confirm', $return), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertRedirect($showUrl)
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->post(route('sales.returns.confirm', $return), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertRedirect($showUrl)
        ->assertSessionHasNoErrors();

    $otherWarehouse = Warehouse::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'code' => 'RETURN-UI-OTHER',
        'name' => 'انبار دیگر مرجوعی',
        'is_active' => true,
        'is_default' => false,
    ]);

    $this->actingAs($context['user'])
        ->post(route('sales.returns.confirm', $return), [
            'warehouse_id' => $otherWarehouse->id,
        ])
        ->assertSessionHasErrors('return');

    $this->actingAs($context['user'])
        ->delete(route('sales.returns.destroy', $return))
        ->assertSessionHasErrors('return');

    $entry = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $return->id)
        ->firstOrFail();

    expect($return->refresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and($return->items)->toHaveCount(1)
        ->and(JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $return->id)
            ->count())->toBe(1)
        ->and($entry->totalDebit())->toBe($entry->totalCredit())
        ->and(InventoryBalance::query()
            ->where('warehouse_id', $context['warehouse']->id)
            ->where('product_id', $context['product']->id)
            ->value('quantity'))->toBe('24.000');
});

it('confirms a purchase return and deletes a draft safely', function () {
    $context = invoiceReturnUiContext();
    $payload = purchaseReturnUiPayload($context);

    $this->actingAs($context['user'])
        ->post(route('purchases.returns.store', $context['purchaseInvoice']), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $return = Invoice::query()
        ->where('type', InvoiceType::PurchaseReturn)
        ->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('purchases.returns.confirm', $return), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertRedirect(route('purchases.returns.show', $return))
        ->assertSessionHasNoErrors();

    expect($return->refresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and(InventoryBalance::query()
            ->where('warehouse_id', $context['warehouse']->id)
            ->where('product_id', $context['product']->id)
            ->value('quantity'))->toBe('20.000');

    $draft = app(PurchaseReturnService::class)->createDraft(
        $context['purchaseInvoice'],
        [[
            'invoice_item_id' => $context['purchaseInvoice']->items()->firstOrFail()->id,
            'quantity' => '1',
        ]],
        $context['date'],
        $context['user']->id,
    );

    $this->actingAs($context['user'])
        ->delete(route('purchases.returns.destroy', $draft))
        ->assertRedirect(route('purchases.show', $context['purchaseInvoice']));

    expect(Invoice::query()->whereKey($draft->id)->exists())->toBeFalse();
});

it('rejects a return dated before its original invoice', function () {
    $context = invoiceReturnUiContext();
    $before = CarbonImmutable::parse($context['date'])->subDay()->toDateString();

    $this->actingAs($context['user'])
        ->post(route('sales.returns.store', $context['salesInvoice']), salesReturnUiPayload(
            $context,
            ['return_date' => $before],
        ))
        ->assertSessionHasErrors('return');

    expect(Invoice::query()->where('type', InvoiceType::SalesReturn)->exists())
        ->toBeFalse();
});

it('rechecks returnable quantities when confirming competing drafts', function () {
    $context = invoiceReturnUiContext();
    $service = app(SalesReturnService::class);
    $itemId = $context['salesInvoice']->items()->firstOrFail()->id;
    $first = $service->createDraft(
        $context['salesInvoice'],
        [['invoice_item_id' => $itemId, 'quantity' => '3']],
        $context['date'],
        $context['user']->id,
        (string) Str::uuid(),
    );
    $second = $service->createDraft(
        $context['salesInvoice'],
        [['invoice_item_id' => $itemId, 'quantity' => '3']],
        $context['date'],
        $context['user']->id,
        (string) Str::uuid(),
    );

    $service->confirm($first, $context['warehouse']->id, $context['user']);

    $this->actingAs($context['user'])
        ->post(route('sales.returns.confirm', $second), [
            'warehouse_id' => $context['warehouse']->id,
        ])
        ->assertSessionHasErrors('return');

    expect($second->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($first->refresh()->status)->toBe(InvoiceStatus::Confirmed);
});

it('allocates the residual discount and tax to the final partial return', function () {
    $context = invoiceReturnUiContext();
    $service = app(SalesReturnService::class);
    $sales = app(SalesService::class);
    $invoice = $sales->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $context['date'],
            'created_by' => $context['user']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 3,
            'unit_price' => 300,
            'discount_amount' => 40,
            'tax_rate' => 10,
        ]],
        'return-ui-rounding-sale-'.$context['user']->id,
    );
    $invoice = $sales->confirm($invoice, $context['warehouse']->id, $context['user']);
    $item = $invoice->items()->firstOrFail();

    $first = $service->createDraft(
        $invoice,
        [['invoice_item_id' => $item->id, 'quantity' => '2']],
        $context['date'],
        $context['user']->id,
        (string) Str::uuid(),
    );
    $service->confirm($first, $context['warehouse']->id, $context['user']);
    $final = $service->createDraft(
        $invoice,
        [['invoice_item_id' => $item->id, 'quantity' => '1']],
        $context['date'],
        $context['user']->id,
        (string) Str::uuid(),
    );
    $service->confirm($final, $context['warehouse']->id, $context['user']);

    $confirmedReturns = Invoice::query()
        ->where('parent_invoice_id', $invoice->id)
        ->where('type', InvoiceType::SalesReturn)
        ->where('status', InvoiceStatus::Confirmed);
    $returnedSubtotal = Decimal::normalize(
        (string) (clone $confirmedReturns)->sum('subtotal'),
        2,
    );
    $returnedDiscount = Decimal::normalize(
        (string) (clone $confirmedReturns)->sum('discount_total'),
        2,
    );
    $returnedTax = Decimal::normalize(
        (string) (clone $confirmedReturns)->sum('tax_total'),
        2,
    );
    $returnedTotal = Decimal::normalize(
        (string) (clone $confirmedReturns)->sum('total'),
        2,
    );
    $returnedCost = Decimal::normalize(
        (string) InvoiceItem::query()
            ->where('parent_invoice_item_id', $item->id)
            ->whereHas('invoice', fn ($query) => $query
                ->where('type', InvoiceType::SalesReturn)
                ->where('status', InvoiceStatus::Confirmed))
            ->sum('total_cost_snapshot'),
        2,
    );

    expect($returnedSubtotal)->toBe($invoice->subtotal)
        ->and($returnedDiscount)->toBe($invoice->discount_total)
        ->and($returnedTax)->toBe($invoice->tax_total)
        ->and($returnedTotal)->toBe($invoice->total)
        ->and($returnedCost)->toBe($item->total_cost_snapshot)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Reversed);
});
