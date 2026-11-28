<?php

use App\Enums\CheckType;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosTender;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Pos\PosPostingContext;
use App\Services\Pos\PosSessionService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\CustomerReceivableService;
use App\Services\Treasury\CheckRegistrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function posContext(): array
{
    test()->travelTo(now()->setDate(2026, 5, 2)->startOfDay());
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $cash = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'cash')->firstOrFail();
    $card = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'card')->firstOrFail();
    $party = Party::query()->create(['store_id' => $store->id, 'code' => 'POS-TEST', 'name' => 'مشتری صندوق']);
    $customer = Customer::query()->create(['party_id' => $party->id, 'credit_limit' => '1000000']);
    $user = User::factory()->create(['role' => UserRole::Cashier]);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $product = Product::query()->create(['code' => 'POS-01', 'name' => 'کالای صندوق', 'unit' => 'عدد', 'purchase_price' => 100, 'sale_price' => 200, 'tax_rate' => 10]);
    app(InventoryService::class)->receive($warehouse->id, $product->id, 10, 100, '2026-05-01', 'pos-opening', InventoryMovementType::Opening);
    $session = app(PosSessionService::class)->open($branch, $user, $warehouse->id, $cash->id, $card->id, '50');

    return compact('store', 'branch', 'warehouse', 'cash', 'card', 'customer', 'user', 'manager', 'product', 'session');
}

function posPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'session_id' => $context['session']->id,
        'submission_token' => fake()->uuid(),
        'customer_id' => $context['customer']->id,
        'due_date' => null,
        'notes' => 'فروش آزمایشی صندوق',
        'items' => [['product_id' => $context['product']->id, 'quantity' => '2', 'unit_price' => '200', 'discount_amount' => '0', 'tax_rate' => '10']],
        'tenders' => [['method' => 'cash', 'amount' => '440', 'confirmed' => true]],
    ], $overrides);
}

it('opens the dedicated POS for sales roles and protects it from other roles', function () {
    $context = posContext();
    $this->actingAs($context['user'])->get(route('pos.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('pos/Index')->where('session.id', $context['session']->id)->where('can_review', false));
    $this->actingAs(User::factory()->create(['role' => UserRole::Warehouse]))->get(route('pos.index'))->assertForbidden();
});

it('limits POS product results to fifteen while searching the full catalog', function () {
    $context = posContext();
    for ($index = 1; $index <= 25; $index++) {
        Product::query()->create([
            'code' => 'LIMIT-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'barcode' => '626000'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
            'name' => 'کالای کاتالوگ '.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'unit' => 'عدد', 'purchase_price' => 100, 'sale_price' => 200,
        ]);
    }
    $this->actingAs($context['user'])->getJson(route('pos.products'))->assertOk()->assertJsonCount(15, 'data');
    $this->getJson(route('pos.products', ['search' => 'LIMIT-025']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'LIMIT-025');
    $this->getJson(route('pos.products', ['search' => '6260000000025']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'LIMIT-025');
    $this->getJson(route('sales.lookups.products', ['warehouse_id' => $context['warehouse']->id]))
        ->assertOk()->assertJsonCount(20, 'data');
});

it('posts a cashier sale and payment once without granting manual journal rights', function () {
    $context = posContext();
    $payload = posPayload($context);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('sale.status', 'paid');
    $this->postJson(route('pos.checkout'), $payload)->assertOk();
    expect(Invoice::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(InventoryBalance::query()->where('product_id', $context['product']->id)->value('quantity'))->toBe('8.000')
        ->and($context['user']->canApproveJournalEntries())->toBeFalse();
    foreach (JournalEntry::query()->get() as $entry) {
        expect($entry->totalDebit())->toBe($entry->totalCredit())->and($entry->created_by)->toBe($context['user']->id);
    }
    $this->postJson(route('sales.confirm', Invoice::query()->first()), ['warehouse_id' => $context['warehouse']->id])->assertForbidden();
    $this->get(route('receipts.create'))->assertForbidden();
});

it('uses the Tehran business date across the UTC midnight boundary', function () {
    $context = posContext();
    $context['store']->forceFill(['timezone' => 'Asia/Tehran'])->save();
    // 21:00 UTC is already 00:30 the next day in Tehran.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 21:00:00', 'UTC'));

    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), posPayload($context))
        ->assertOk();

    expect(Invoice::query()->firstOrFail()->invoice_date->toDateString())
        ->toBe('2026-11-25');
});

it('checks out service items without creating inventory movements or COGS', function () {
    $context = posContext();
    $service = Product::query()->create([
        'code' => 'POS-SERVICE',
        'name' => 'خدمت صندوق',
        'unit' => 'پروژه',
        'purchase_price' => 100,
        'sale_price' => 500,
        'tax_rate' => 10,
        'item_type' => 'service',
    ]);
    $movementsBefore = InventoryMovement::query()->count();
    $payload = posPayload($context, [
        'items' => [[
            'product_id' => $service->id,
            'quantity' => '2',
            'unit_price' => '500',
            'discount_amount' => '0',
            'tax_rate' => '10',
        ]],
        'tenders' => [['method' => 'cash', 'amount' => '1100', 'confirmed' => true]],
    ]);

    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), $payload)
        ->assertOk();

    $invoice = Invoice::query()->firstOrFail();
    expect(InventoryMovement::query()->count())->toBe($movementsBefore)
        ->and($invoice->items()->firstOrFail()->total_cost_snapshot)->toBe('0.00')
        ->and($invoice->total)->toBe('1100.00');
});

it('ignores a client-supplied VAT rate and uses the catalog rate', function () {
    $context = posContext();
    $payload = posPayload($context, [
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity' => '2',
            'unit_price' => '200',
            'discount_amount' => '0',
            'tax_rate' => '0',
        ]],
        'tenders' => [['method' => 'cash', 'amount' => '440', 'confirmed' => true]],
    ]);

    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), $payload)
        ->assertOk();

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->items()->firstOrFail()->tax_rate)->toEqual('10.00')
        ->and($invoice->tax_total)->toBe('40.00')
        ->and($invoice->total)->toBe('440.00');
});

it('rejects a second registration of the same external check identity', function () {
    $context = posContext();
    $fiscalYear = $context['store']->fiscalYears()->where('is_current', true)->firstOrFail();
    $registration = app(CheckRegistrationService::class);

    $registration->register(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $fiscalYear->id,
        type: CheckType::Receivable,
        partyProfileId: $context['customer']->id,
        checkNumber: 'DUP-100',
        bankName: 'بانک ملت',
        branchName: null,
        amount: '100.00',
        issueDate: '2026-05-02',
        dueDate: '2026-06-02',
        creator: $context['manager'],
        idempotencyKey: fake()->uuid(),
    );

    expect(fn () => $registration->register(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $fiscalYear->id,
        type: CheckType::Receivable,
        partyProfileId: $context['customer']->id,
        checkNumber: ' DUP-100 ',
        bankName: ' بانک ملت ',
        branchName: null,
        amount: '100.00',
        issueDate: '2026-05-02',
        dueDate: '2026-06-02',
        creator: $context['manager'],
        idempotencyKey: fake()->uuid(),
    ))->toThrow(DomainException::class, 'قبلاً با همان بانک و شماره ثبت شده');

    expect(Check::query()->count())->toBe(1);
});

it('keeps check declarations and credit outstanding until the accountant registers the check', function () {
    $context = posContext();
    $payload = posPayload($context, ['due_date' => '2026-06-01', 'tenders' => [
        ['method' => 'cash', 'amount' => '100', 'confirmed' => true],
        ['method' => 'card', 'amount' => '100', 'confirmed' => true, 'reference' => '123'],
        ['method' => 'check', 'amount' => '200', 'check_received' => false, 'notes' => 'فردا تحویل می‌دهد'],
        ['method' => 'credit', 'amount' => '40'],
    ]]);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('sale.outstanding', '240.00');
    expect(Check::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(2);
    $tender = PosTender::query()->where('method', 'check')->firstOrFail();
    $registration = ['check_number' => '123456', 'bank_name' => 'ملت', 'due_date' => '2026-07-01', 'receipt_date' => '2026-05-02'];
    $this->postJson(route('pos.checks.register', $tender), $registration)->assertForbidden();
    $this->actingAs($context['manager'])->postJson(route('pos.checks.register', $tender), $registration)->assertOk();
    $this->postJson(route('pos.checks.register', $tender), $registration)->assertOk();
    expect(Check::query()->count())->toBe(1)->and(Receipt::query()->count())->toBe(3)
        ->and(app(CustomerReceivableService::class)->outstandingForInvoice(Invoice::query()->first()))->toBe('40.00')
        ->and($tender->fresh()->check_received)->toBeTrue();
});

it('resolves a POS check against its server-validated invoice after the customer is deactivated', function () {
    $context = posContext();
    $payload = posPayload($context, [
        'due_date' => '2026-06-01',
        'tenders' => [['method' => 'check', 'amount' => '440', 'check_received' => false]],
    ]);
    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), $payload)
        ->assertOk();
    $tender = PosTender::query()->where('method', 'check')->firstOrFail();
    $context['customer']->update(['is_active' => false]);
    $context['customer']->party()->update(['is_active' => false]);
    $registration = ['check_number' => 'INACTIVE-POS', 'bank_name' => 'ملت', 'due_date' => '2026-07-01', 'receipt_date' => '2026-05-02'];

    $this->actingAs($context['manager'])
        ->postJson(route('pos.checks.register', $tender), $registration)
        ->assertOk();
    $this->postJson(route('pos.checks.register', $tender), $registration)->assertOk();

    expect(Check::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and($tender->fresh()->resolved_at)->not->toBeNull();
});

it('rejects POS check resolution when the invoice outstanding is below the tender', function () {
    $context = posContext();
    $payload = posPayload($context, [
        'due_date' => '2026-06-01',
        'tenders' => [['method' => 'check', 'amount' => '440', 'check_received' => false]],
    ]);
    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), $payload)
        ->assertOk();
    $invoice = Invoice::query()->firstOrFail();
    app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $invoice->fiscal_year_id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-02',
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $invoice->id, 'amount' => '100']],
        approver: $context['manager'],
        idempotencyKey: 'pos-reduce-outstanding',
        treasuryAccountId: $context['cash']->id,
    );
    $tender = PosTender::query()->where('method', 'check')->firstOrFail();

    $this->actingAs($context['manager'])
        ->postJson(route('pos.checks.register', $tender), [
            'check_number' => 'INSUFFICIENT-POS',
            'bank_name' => 'ملت',
            'due_date' => '2026-07-01',
            'receipt_date' => '2026-05-02',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pos');

    expect(Check::query()->count())->toBe(0)
        ->and($tender->fresh()->resolved_at)->toBeNull();
});

it('still rejects a new POS checkout for a deactivated customer or party', function (bool $deactivateParty) {
    $context = posContext();
    $context['customer']->update(['is_active' => false]);

    if ($deactivateParty) {
        $context['customer']->party()->update(['is_active' => false]);
    }

    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), posPayload($context))
        ->assertUnprocessable();

    expect(Invoice::query()->count())->toBe(0);
})->with([
    'customer profile' => [false],
    'party' => [true],
]);

it('allows anonymous fully paid sales with zero credit and rejects anonymous credit or checks', function () {
    $context = posContext();
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context, ['customer_id' => null]))
        ->assertOk()->assertJsonPath('sale.status', 'paid');
    $this->postJson(route('pos.checkout'), posPayload($context, ['customer_id' => null, 'due_date' => '2026-06-01', 'tenders' => [['method' => 'credit', 'amount' => '440']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('pos');
    expect(PosSale::query()->count())->toBe(1);
});

it('checks credit only on the unpaid portion but still enforces its limit', function () {
    $context = posContext();
    $context['customer']->update(['credit_limit' => '40']);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context, ['due_date' => '2026-06-01', 'tenders' => [
        ['method' => 'cash', 'amount' => '400', 'confirmed' => true], ['method' => 'credit', 'amount' => '40'],
    ]]))->assertOk();
    $this->postJson(route('pos.checkout'), posPayload($context, ['due_date' => '2026-06-01', 'tenders' => [['method' => 'credit', 'amount' => '440']]]))->assertUnprocessable();
    expect(PosSale::query()->count())->toBe(1);
    $context['customer']->update(['credit_limit' => '0']);
    $this->postJson(route('pos.checkout'), posPayload($context))->assertOk();
});

it('rolls back invoice inventory and journals if a card account is missing', function () {
    $context = posContext();
    $context['session']->update(['card_account_id' => null]);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context, ['tenders' => [['method' => 'card', 'amount' => '440', 'confirmed' => true]]]))->assertUnprocessable();
    expect(Invoice::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0)
        ->and(PosSale::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(0)
        ->and(InventoryBalance::query()->where('product_id', $context['product']->id)->value('quantity'))->toBe('10.000');
});

it('rejects changed retries, incorrect splits and unconfirmed receipts', function () {
    $context = posContext();
    $payload = posPayload($context);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk();
    $this->postJson(route('pos.checkout'), [...$payload, 'notes' => 'تغییر'])->assertUnprocessable();
    $this->postJson(route('pos.checkout'), posPayload($context, ['tenders' => [['method' => 'cash', 'amount' => '430', 'confirmed' => true]]]))->assertUnprocessable();
    $this->postJson(route('pos.checkout'), posPayload($context, ['tenders' => [['method' => 'card', 'amount' => '440', 'confirmed' => false]]]))->assertUnprocessable();
    expect(Invoice::query()->count())->toBe(1)->and(Receipt::query()->count())->toBe(1);
});

it('prevents overselling and prevents using someone elses shift', function () {
    $context = posContext();
    $payload = posPayload($context);
    $payload['items'][0]['quantity'] = '11';
    $payload['tenders'][0]['amount'] = '2420';
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertUnprocessable();
    $this->actingAs($context['manager'])->postJson(route('pos.checkout'), posPayload($context))->assertForbidden();
    expect(Invoice::query()->count())->toBe(0);
});

it('closes a shift without reposting sales and requires an explanation for a cash difference', function () {
    $context = posContext();
    $payload = posPayload($context);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk();
    $this->postJson(route('pos.close', $context['session']), ['counted_cash' => '480'])->assertUnprocessable();
    $this->postJson(route('pos.close', $context['session']), ['counted_cash' => '480', 'notes' => 'ده ریال کسری'])->assertOk();
    expect($context['session']->fresh()->expected_cash)->toBe('490.00')
        ->and($context['session']->fresh()->cash_difference)->toBe('-10.00')
        ->and(PosSession::query()->whereNotNull('active_user_id')->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(2);
    // A lost response can safely be recovered even after closing the shift.
    $this->postJson(route('pos.checkout'), $payload)->assertOk();
    $this->postJson(route('pos.checkout'), posPayload($context))->assertUnprocessable();
});

it('prevents a second active shift on an occupied cash drawer', function () {
    $context = posContext();
    $this->actingAs($context['manager'])->postJson(route('pos.open'), [
        'warehouse_id' => $context['warehouse']->id, 'cash_account_id' => $context['cash']->id, 'opening_cash' => '0',
    ])->assertUnprocessable();
    expect(PosSession::query()->count())->toBe(1);
});

it('does not let POS authorization post arbitrary or already completed source documents', function () {
    $context = posContext();
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context))->assertOk();
    $sale = PosSale::query()->firstOrFail();
    $entry = JournalEntry::query()->firstOrFail();
    expect((new PosPostingContext($sale->id))->allows($entry, $context['user']))->toBeFalse();
    expect(fn () => app(PostingService::class)->approveAndPost($entry, $context['user'], new PosPostingContext($sale->id)))
        ->toThrow(AuthorizationException::class);
});

it('uses the invoice party snapshot for POS sale and pending check display', function () {
    $context = posContext();
    $payload = posPayload($context, [
        'due_date' => '2026-06-01',
        'tenders' => [['method' => 'check', 'amount' => '440', 'check_received' => false]],
    ]);

    $this->actingAs($context['user'])
        ->postJson(route('pos.checkout'), $payload)
        ->assertOk()
        ->assertJsonPath('sale.customer_name', 'مشتری صندوق')
        ->assertJsonPath('sale.customer_legacy_current', false);

    $context['customer']->party()->update(['name' => 'نام فعلی مشتری']);

    $this->getJson(route('pos.attempt', ['token' => $payload['submission_token']]))
        ->assertOk()
        ->assertJsonPath('sale.customer_name', 'مشتری صندوق')
        ->assertJsonPath('sale.customer_legacy_current', false);

    $this->getJson(route('pos.activity'))
        ->assertOk()
        ->assertJsonPath('recent_sales.0.customer_name', 'مشتری صندوق')
        ->assertJsonPath('recent_sales.0.customer_legacy_current', false);

    $this->actingAs($context['manager'])
        ->getJson(route('pos.activity'))
        ->assertOk()
        ->assertJsonPath('pending_checks.0.customer_name', 'مشتری صندوق')
        ->assertJsonPath('pending_checks.0.customer_legacy_current', false);
});

it('labels current Party fallback for legacy POS invoices and pending checks', function () {
    $context = posContext();
    $payload = posPayload($context, [
        'due_date' => '2026-06-01',
        'tenders' => [['method' => 'check', 'amount' => '440', 'check_received' => false]],
    ]);

    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk();
    $invoice = Invoice::query()->firstOrFail();
    DB::table('invoices')->where('id', $invoice->id)->update(['party_snapshot' => null]);
    $context['customer']->party()->update([
        'name' => 'مشتری جاری سند قدیمی',
        'phone' => '09120000000',
        'email' => 'legacy@example.test',
        'national_id' => '1000000001',
        'tax_id' => '2000000001',
        'address' => 'آدرس مشتری جاری',
    ]);

    $this->getJson(route('pos.activity'))
        ->assertOk()
        ->assertJsonPath('recent_sales.0.customer_name', 'مشتری جاری سند قدیمی')
        ->assertJsonPath('recent_sales.0.customer_legacy_current', true);

    $this->actingAs($context['manager'])
        ->getJson(route('pos.activity'))
        ->assertOk()
        ->assertJsonPath('pending_checks.0.customer_name', 'مشتری جاری سند قدیمی')
        ->assertJsonPath('pending_checks.0.customer_legacy_current', true);
});
