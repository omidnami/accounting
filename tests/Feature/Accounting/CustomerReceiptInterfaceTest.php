<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function receiptUiContext(UserRole $role = UserRole::Manager): array
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
        'code' => 'RECEIPT-CUSTOMER',
        'name' => 'مشتری دریافت',
        'phone' => '09121234567',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'RECEIPT-PRODUCT',
        'name' => 'کالای دریافت',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $date = $fiscalYear->starts_on->toDateString();

    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 20,
        unitCost: 100,
        movementDate: $date,
        idempotencyKey: 'receipt-ui-opening-'.$user->id,
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

function createReceiptTestInvoice(
    array $context,
    string|int|float $quantity = 2,
    string $suffix = '1',
): Invoice {
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
            'quantity' => $quantity,
            'unit_price' => '200',
            'discount_amount' => '0',
            'tax_rate' => '10',
        ]],
        'receipt-ui-sale-'.$suffix,
    );

    return $sales->confirm($invoice, $context['warehouse']->id, $context['user']);
}

/**
 * @return array<string, mixed>
 */
function receiptUiPayload(array $context, Invoice $invoice, array $overrides = []): array
{
    return array_replace([
        'submission_token' => fake()->uuid(),
        'customer_id' => $context['customer']->id,
        'receipt_date' => $context['date'],
        'payment_method' => 'cash',
        'treasury_account_id' => null,
        'reference' => 'RCP-REF-1',
        'description' => 'دریافت آزمایشی',
        'allocations' => [[
            'invoice_id' => $invoice->id,
            'amount' => '100',
        ]],
    ], $overrides);
}

it('protects customer receipt pages by role', function () {
    $this->get(route('receipts.index'))->assertRedirect(route('login'));

    $cashier = receiptUiContext(UserRole::Cashier)['user'];

    $this->actingAs($cashier)
        ->get(route('receipts.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->post(route('receipts.store'), [])
        ->assertForbidden();
});

it('allows managers and accountants into customer receipts', function (UserRole $role) {
    $context = receiptUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('receipts.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('receipts/Create')
            ->has('treasury_accounts')
            ->where('initial_customer', null));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('prefills an open invoice from the sales interface', function () {
    $context = receiptUiContext();
    $invoice = createReceiptTestInvoice($context);

    $this->actingAs($context['user'])
        ->get(route('receipts.create', [
            'customer_id' => $context['customer']->id,
            'invoice_id' => $invoice->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('receipts/Create')
            ->where('initial_customer.id', $context['customer']->id)
            ->where('initial_invoice_id', $invoice->id)
            ->has('initial_invoices', 1)
            ->where('initial_invoices.0.outstanding', $invoice->total));
});

it('registers a partial cash receipt and updates the invoice settlement', function () {
    $context = receiptUiContext();
    $invoice = createReceiptTestInvoice($context);
    $payload = receiptUiPayload($context, $invoice);

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $receipt = Receipt::query()->firstOrFail();
    $entry = JournalEntry::query()
        ->where('source_type', Receipt::class)
        ->where('source_id', $receipt->id)
        ->firstOrFail();

    expect($receipt->amount)->toBe('100.00')
        ->and($receipt->allocations)->toHaveCount(1)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($entry->totalDebit())->toBe('100.00')
        ->and($entry->totalCredit())->toBe('100.00');

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), $payload)
        ->assertRedirect();

    expect(Receipt::query()->count())->toBe(1);
});

it('allocates one receipt across multiple customer invoices', function () {
    $context = receiptUiContext();
    $invoiceA = createReceiptTestInvoice($context, 2, 'A');
    $invoiceB = createReceiptTestInvoice($context, 1, 'B');

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), receiptUiPayload($context, $invoiceA, [
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'amount' => '100'],
                ['invoice_id' => $invoiceB->id, 'amount' => '50'],
            ],
        ]))
        ->assertRedirect();

    expect(Receipt::query()->firstOrFail()->amount)->toBe('150.00')
        ->and($invoiceA->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoiceB->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid);
});

it('rejects allocations above invoice outstanding balances', function () {
    $context = receiptUiContext();
    $invoice = createReceiptTestInvoice($context);

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), receiptUiPayload($context, $invoice, [
            'allocations' => [[
                'invoice_id' => $invoice->id,
                'amount' => '999999',
            ]],
        ]))
        ->assertSessionHasErrors('receipt');

    expect(Receipt::query()->count())->toBe(0)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Confirmed);
});

it('shows receipts and their allocated invoices', function () {
    $context = receiptUiContext();
    $invoice = createReceiptTestInvoice($context);

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), receiptUiPayload($context, $invoice));

    $receipt = Receipt::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('receipts.show', $receipt))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('receipts/Show')
            ->where('receipt.id', $receipt->id)
            ->where('receipt.allocations.0.invoice.id', $invoice->id));
});

it('passes receivable status to the sales invoice page', function () {
    $context = receiptUiContext();
    $invoice = createReceiptTestInvoice($context);

    $this->actingAs($context['user'])
        ->get(route('sales.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_receive', true)
            ->where('outstanding', $invoice->total));
});
