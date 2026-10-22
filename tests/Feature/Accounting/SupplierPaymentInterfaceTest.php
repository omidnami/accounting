<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Purchasing\PurchaseService;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function supplierPaymentUiContext(UserRole $role = UserRole::Manager): array
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
        'code' => 'PAY-UI-SUPPLIER',
        'name' => 'تأمین‌کننده رابط پرداخت',
        'phone' => '09121234567',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
        'payment_terms' => '۳۰ روزه',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'PAY-UI-PRODUCT',
        'name' => 'کالای پرداخت رابط',
        'purchase_price' => 200,
        'sale_price' => 300,
        'tax_rate' => 10,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $date = $fiscalYear->starts_on->toDateString();
    $purchases = app(PurchaseService::class);
    $invoice = $purchases->createDraft(
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
            'quantity' => '2',
            'unit_price' => '200',
            'discount_amount' => '0',
            'tax_rate' => '10',
        ]],
        'payment-ui-purchase-'.$user->id,
    );
    $invoice = $purchases->confirm($invoice, $warehouse->id, $user);

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'party',
        'supplier',
        'product',
        'user',
        'invoice',
        'date',
    );
}

/**
 * @return array<string, mixed>
 */
function supplierPaymentPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'submission_token' => fake()->uuid(),
        'supplier_id' => $context['supplier']->id,
        'payment_date' => $context['date'],
        'payment_method' => 'cash',
        'treasury_account_id' => null,
        'reference' => 'PAY-REF-1',
        'description' => 'پرداخت آزمایشی',
        'allocations' => [[
            'invoice_id' => $context['invoice']->id,
            'amount' => '100',
        ]],
    ], $overrides);
}

it('protects supplier payment pages by role', function () {
    $this->get(route('payments.index'))->assertRedirect(route('login'));
    supplierPaymentUiContext();
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();

    $this->actingAs($cashier)
        ->get(route('payments.index'))
        ->assertForbidden();
});

it('allows managers and accountants into supplier payments', function (UserRole $role) {
    $context = supplierPaymentUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('payments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payments/Create')
            ->has('treasury_accounts')
            ->where('initial_supplier', null));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('prefills a payable purchase invoice', function () {
    $context = supplierPaymentUiContext();

    $this->actingAs($context['user'])
        ->get(route('payments.create', [
            'supplier_id' => $context['supplier']->id,
            'invoice_id' => $context['invoice']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payments/Create')
            ->where('initial_supplier.id', $context['supplier']->id)
            ->where('initial_invoice_id', $context['invoice']->id)
            ->has('initial_invoices', 1));
});

it('registers a partial supplier payment with accounting and idempotency', function () {
    $context = supplierPaymentUiContext();
    $payload = supplierPaymentPayload($context);

    $this->actingAs($context['user'])
        ->post(route('payments.store'), $payload)
        ->assertRedirect();

    $payment = Payment::query()->firstOrFail();
    $entry = JournalEntry::query()
        ->where('source_type', Payment::class)
        ->where('source_id', $payment->id)
        ->firstOrFail();

    expect($payment->amount)->toBe('100.00')
        ->and($context['invoice']->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($entry->totalDebit())->toBe('100.00')
        ->and($entry->totalCredit())->toBe('100.00');

    $this->actingAs($context['user'])
        ->post(route('payments.store'), $payload)
        ->assertRedirect();

    expect(Payment::query()->count())->toBe(1);

    $this->actingAs($context['user'])
        ->post(route('payments.store'), array_replace($payload, [
            'reference' => 'DIFFERENT',
        ]))
        ->assertSessionHasErrors('payment');
});

it('rejects payment above outstanding and renders payment pages', function () {
    $context = supplierPaymentUiContext();

    $this->actingAs($context['user'])
        ->post(route('payments.store'), supplierPaymentPayload($context, [
            'allocations' => [[
                'invoice_id' => $context['invoice']->id,
                'amount' => '999999',
            ]],
        ]))
        ->assertSessionHasErrors('payment');

    expect(Payment::query()->count())->toBe(0);

    $this->actingAs($context['user'])
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payments/Index')
            ->has('payments.data'));

    $this->actingAs($context['user'])
        ->get(route('purchases.show', $context['invoice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_pay', true)
            ->where('payable', fn ($value) => is_string($value)));
});

it('rejects payment when the supplier is deactivated before paying', function () {
    $context = supplierPaymentUiContext();
    $context['supplier']->update(['is_active' => false]);

    $this->actingAs($context['user'])
        ->post(route('payments.store'), supplierPaymentPayload($context))
        ->assertSessionHasErrors('payment');

    expect(Payment::query()->count())->toBe(0)
        ->and($context['invoice']->refresh()->status)->toBe(InvoiceStatus::Confirmed);
});
