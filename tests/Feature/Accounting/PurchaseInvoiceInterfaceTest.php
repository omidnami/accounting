<?php

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
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
        ->assertJsonPath('data.0.id', $context['product']->id);
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
