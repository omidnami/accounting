<?php

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SalesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function treasuryCheckUiContext(UserRole $role = UserRole::Manager): array
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
        'code' => 'CHECK-UI-CUSTOMER',
        'name' => 'مشتری چک رابط',
        'phone' => '09120000001',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CHECK-UI-SUPPLIER',
        'name' => 'تأمین‌کننده چک رابط',
        'phone' => '09120000002',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'CHECK-UI-PRODUCT',
        'name' => 'کالای چک رابط',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
        'is_active' => true,
    ]);
    $date = $fiscalYear->starts_on->toDateString();
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 100,
        movementDate: $date,
        idempotencyKey: 'check-ui-opening-'.$user->id,
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
            'quantity' => 2,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 10,
        ]],
        'check-ui-sale-'.$user->id,
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
            'quantity' => 2,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 10,
        ]],
        'check-ui-purchase-'.$user->id,
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
        'salesInvoice',
        'purchaseInvoice',
        'date',
    );
}

/**
 * @return array<string, mixed>
 */
function treasuryCheckPayload(
    array $context,
    CheckType $type = CheckType::Receivable,
    array $overrides = [],
): array {
    return array_replace([
        'submission_token' => fake()->uuid(),
        'type' => $type->value,
        'customer_id' => $type === CheckType::Receivable
            ? $context['customer']->id
            : null,
        'supplier_id' => $type === CheckType::Payable
            ? $context['supplier']->id
            : null,
        'check_number' => $type === CheckType::Receivable ? 'R-UI-100' : 'P-UI-100',
        'bank_name' => 'بانک رابط',
        'branch_name' => 'شعبه مرکزی',
        'amount' => '100',
        'issue_date' => $context['date'],
        'due_date' => $context['date'],
        'description' => 'چک آزمایشی رابط',
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function treasuryCheckReceiptPayload(array $context, Check $check): array
{
    return [
        'submission_token' => fake()->uuid(),
        'customer_id' => $context['customer']->id,
        'receipt_date' => $context['date'],
        'payment_method' => 'check',
        'check_id' => $check->id,
        'treasury_account_id' => null,
        'reference' => 'CHECK-RCPT-'.$check->check_number,
        'description' => 'دریافت چکی آزمایشی',
        'allocations' => [[
            'invoice_id' => $context['salesInvoice']->id,
            'amount' => '100',
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function treasuryCheckPaymentPayload(array $context, Check $check): array
{
    return [
        'submission_token' => fake()->uuid(),
        'supplier_id' => $context['supplier']->id,
        'payment_date' => $context['date'],
        'payment_method' => 'check',
        'check_id' => $check->id,
        'treasury_account_id' => null,
        'reference' => 'CHECK-PAY-'.$check->check_number,
        'description' => 'پرداخت چکی آزمایشی',
        'allocations' => [[
            'invoice_id' => $context['purchaseInvoice']->id,
            'amount' => '100',
        ]],
    ];
}

it('protects treasury check pages and actions by role', function () {
    $this->get(route('checks.index'))->assertRedirect(route('login'));
    treasuryCheckUiContext();
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();

    $this->actingAs($cashier)
        ->get(route('checks.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->get(route('checks.create'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->post(route('checks.store'), [])
        ->assertForbidden();
});

it('allows managers and accountants into treasury checks', function (UserRole $role) {
    $context = treasuryCheckUiContext($role);

    $this->actingAs($context['user'])
        ->get(route('checks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checks/Index')
            ->has('checks.data'));

    $this->actingAs($context['user'])
        ->get(route('checks.create', [
            'type' => 'receivable',
            'customer_id' => $context['customer']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checks/Create')
            ->where('defaults.type', 'receivable')
            ->where('initial_party.id', $context['customer']->id));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('registers a check idempotently and rejects a reused token with changed data', function () {
    $context = treasuryCheckUiContext();
    $payload = treasuryCheckPayload($context);

    $this->actingAs($context['user'])
        ->post(route('checks.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $check = Check::query()->firstOrFail();

    expect($check->status)->toBe(CheckStatus::Pending)
        ->and($check->document_number)->toStartWith('CK-')
        ->and($check->type)->toBe(CheckType::Receivable)
        ->and($check->amount)->toBe('100.00')
        ->and($check->store_id)->toBe($context['store']->id)
        ->and($check->branch_id)->toBe($context['branch']->id);

    $this->actingAs($context['user'])
        ->post(route('checks.store'), $payload)
        ->assertRedirect(route('checks.show', $check));

    expect(Check::query()->count())->toBe(1);

    $this->actingAs($context['user'])
        ->post(route('checks.store'), array_replace($payload, [
            'bank_name' => 'بانک متفاوت',
        ]))
        ->assertSessionHasErrors('check');

    expect(Check::query()->count())->toBe(1);
});

it('allows the same external check number for different parties', function () {
    $context = treasuryCheckUiContext();
    $secondParty = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'CHECK-UI-CUSTOMER-2',
        'name' => 'مشتری دوم چک',
        'is_active' => true,
    ]);
    $secondCustomer = Customer::query()->create([
        'party_id' => $secondParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $payload = treasuryCheckPayload($context, CheckType::Receivable, [
        'check_number' => 'SHARED-123',
        'bank_name' => 'بانک مشترک',
    ]);

    $this->actingAs($context['user'])
        ->post(route('checks.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->post(route('checks.store'), array_replace($payload, [
            'submission_token' => fake()->uuid(),
            'customer_id' => $secondCustomer->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Check::query()->count())->toBe(2)
        ->and(Check::query()->pluck('document_number')->unique())->toHaveCount(2);
});

it('links a pending customer check to a receipt and collects it once', function () {
    $context = treasuryCheckUiContext();

    $this->actingAs($context['user'])
        ->post(route('checks.store'), treasuryCheckPayload($context))
        ->assertRedirect();

    $check = Check::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), treasuryCheckReceiptPayload($context, $check))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->get(route('receipts.create', [
            'customer_id' => $context['customer']->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('initial_checks', 0));

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), treasuryCheckReceiptPayload($context, $check))
        ->assertSessionHasErrors('receipt');

    expect(Receipt::query()->count())->toBe(1);

    $this->actingAs($context['user'])
        ->get(route('receipts.show', Receipt::query()->firstOrFail()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.check.id', $check->id)
            ->where('receipt.check.document_number', $check->document_number));

    $this->actingAs($context['user'])
        ->get(route('checks.show', $check))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checks/Show')
            ->where('check.status', 'pending')
            ->where('check.treasury_document.kind', 'receipt')
            ->has('bank_accounts'));

    $settlement = [
        'settlement_date' => $context['date'],
        'treasury_account_id' => null,
    ];

    $this->actingAs($context['user'])
        ->post(route('checks.settle', $check), $settlement)
        ->assertRedirect(route('checks.show', $check))
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->post(route('checks.settle', $check), $settlement)
        ->assertRedirect(route('checks.show', $check));

    $this->actingAs($context['user'])
        ->post(route('checks.settle', $check), [
            ...$settlement,
            'settlement_date' => CarbonImmutable::parse($context['date'])
                ->addDay()
                ->toDateString(),
        ])
        ->assertSessionHasErrors('check');

    $entries = JournalEntry::query()
        ->where('source_type', Check::class)
        ->where('source_id', $check->id)
        ->get();

    expect($check->refresh()->status)->toBe(CheckStatus::Collected)
        ->and($entries)->toHaveCount(1)
        ->and($entries->first()->totalDebit())->toBe('100.00')
        ->and($entries->first()->totalCredit())->toBe('100.00');
});

it('links a pending payable check to a payment and marks it spent', function () {
    $context = treasuryCheckUiContext();

    $this->actingAs($context['user'])
        ->post(route('checks.store'), treasuryCheckPayload(
            $context,
            CheckType::Payable,
        ))
        ->assertRedirect();

    $check = Check::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('payments.store'), treasuryCheckPaymentPayload($context, $check))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->post(route('checks.settle', $check), [
            'settlement_date' => $context['date'],
            'treasury_account_id' => null,
        ])
        ->assertRedirect(route('checks.show', $check))
        ->assertSessionHasNoErrors();

    expect($check->refresh()->status)->toBe(CheckStatus::Spent)
        ->and(JournalEntry::query()
            ->where('source_type', Check::class)
            ->where('source_id', $check->id)
            ->exists())->toBeTrue();
});

it('rolls back a failed settlement and supports a controlled bounce', function () {
    $context = treasuryCheckUiContext();

    $this->actingAs($context['user'])
        ->post(route('checks.store'), treasuryCheckPayload($context))
        ->assertRedirect();

    $check = Check::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), treasuryCheckReceiptPayload($context, $check))
        ->assertRedirect();

    TreasuryAccount::query()
        ->where('branch_id', $context['branch']->id)
        ->update(['is_active' => false]);

    $this->actingAs($context['user'])
        ->post(route('checks.settle', $check), [
            'settlement_date' => $context['date'],
            'treasury_account_id' => null,
        ])
        ->assertSessionHasErrors('check');

    expect($check->refresh()->status)->toBe(CheckStatus::Pending)
        ->and(JournalEntry::query()
            ->where('source_type', Check::class)
            ->where('source_id', $check->id)
            ->exists())->toBeFalse();

    $this->actingAs($context['user'])
        ->post(route('checks.bounce', $check), [
            'bounce_date' => $context['date'],
        ])
        ->assertRedirect(route('checks.show', $check))
        ->assertSessionHasNoErrors();

    expect($check->refresh()->status)->toBe(CheckStatus::Bounced)
        ->and(JournalEntry::query()
            ->where('source_type', Check::class)
            ->where('source_id', $check->id)
            ->exists())->toBeTrue();

    $this->actingAs($context['user'])
        ->post(route('checks.bounce', $check), [
            'bounce_date' => CarbonImmutable::parse($context['date'])
                ->addDay()
                ->toDateString(),
        ])
        ->assertSessionHasErrors('check');
});

it('returns a validation error when settlement date has no fiscal period', function () {
    $context = treasuryCheckUiContext();

    $this->actingAs($context['user'])
        ->post(route('checks.store'), treasuryCheckPayload($context))
        ->assertRedirect();

    $check = Check::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('receipts.store'), treasuryCheckReceiptPayload($context, $check))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($context['user'])
        ->post(route('checks.settle', $check), [
            'settlement_date' => '2099-01-01',
            'treasury_account_id' => null,
        ])
        ->assertSessionHasErrors('check');

    expect($check->refresh()->status)->toBe(CheckStatus::Pending)
        ->and(JournalEntry::query()
            ->where('source_type', Check::class)
            ->where('source_id', $check->id)
            ->exists())->toBeFalse();
});

it('returns to the receipt form with selected invoices and check', function () {
    $context = treasuryCheckUiContext();
    $payload = treasuryCheckPayload($context, CheckType::Receivable, [
        'return_to' => 'receipt',
        'invoice_ids' => (string) $context['salesInvoice']->id,
    ]);

    $this->actingAs($context['user'])
        ->post(route('checks.store'), $payload)
        ->assertRedirect(route('receipts.create', [
            'customer_id' => $context['customer']->id,
            'check_id' => Check::query()->firstOrFail()->id,
            'invoice_ids' => (string) $context['salesInvoice']->id,
        ]));

    $check = Check::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('receipts.create', [
            'customer_id' => $context['customer']->id,
            'check_id' => $check->id,
            'invoice_ids' => (string) $context['salesInvoice']->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initial_check_id', $check->id)
            ->where('initial_invoice_ids', [$context['salesInvoice']->id])
            ->has('initial_invoices', 1));
});

it('returns to the supplier payment form with selected invoices and check', function () {
    $context = treasuryCheckUiContext();
    $payload = treasuryCheckPayload($context, CheckType::Payable, [
        'return_to' => 'payment',
        'invoice_ids' => (string) $context['purchaseInvoice']->id,
    ]);

    $this->actingAs($context['user'])
        ->post(route('checks.store'), $payload)
        ->assertRedirect(route('payments.create', [
            'supplier_id' => $context['supplier']->id,
            'check_id' => Check::query()->firstOrFail()->id,
            'invoice_ids' => (string) $context['purchaseInvoice']->id,
        ]));

    $check = Check::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('payments.create', [
            'supplier_id' => $context['supplier']->id,
            'check_id' => $check->id,
            'invoice_ids' => (string) $context['purchaseInvoice']->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initial_check_id', $check->id)
            ->where('initial_invoice_ids', [$context['purchaseInvoice']->id])
            ->has('initial_invoices', 1));
});
