<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JournalEntryStatus;
use App\Enums\PaymentMethod;
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
use App\Models\Receipt;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\PeriodClosingService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountSeeder;

function createSalesContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_default', true)->firstOrFail();
    $fiscalYear = FiscalYear::query()->where('store_id', $store->id)->where('title', '1405')->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'SALES-CUSTOMER',
        'name' => 'مشتری فروش',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 10_000_000,
    ]);
    $product = Product::query()->create([
        'code' => 'SALES-P-001',
        'name' => 'کالای فروش',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
    ]);
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 100,
        movementDate: '2026-05-01',
        idempotencyKey: 'sales-opening-stock',
        movementType: InventoryMovementType::Opening,
    );

    return compact('store', 'branch', 'warehouse', 'fiscalYear', 'party', 'customer', 'product', 'manager');
}

function createSalesInvoice(array $context, int|float $quantity, string $date = '2026-05-02'): Invoice
{
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $date,
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => $quantity,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );

    return $service->confirm($invoice, $context['warehouse']->id, $context['manager']);
}

it('confirms a sale with inventory issue, COGS, tax, and a balanced journal', function () {
    $context = createSalesContext();
    $invoice = createSalesInvoice($context, 4);
    $balance = InventoryBalance::query()->where('product_id', $context['product']->id)->firstOrFail();
    $journal = JournalEntry::query()->where('source_type', Invoice::class)->where('source_id', $invoice->id)->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Confirmed)
        ->and($invoice->total)->toBe('880.00')
        ->and($balance->quantity)->toBe('6.000')
        ->and($balance->inventory_value)->toBe('600.00')
        ->and($invoice->items->first()->total_cost_snapshot)->toBe('400.00')
        ->and($journal->totalDebit())->toBe('1280.00')
        ->and($journal->totalCredit())->toBe('1280.00')
        ->and($journal->posted_by)->toBe($context['manager']->id);
});

it('captures the customer identity when a sales draft is created and preserves it on confirmation', function () {
    $context = createSalesContext();
    $invoice = app(SalesService::class)->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
    $creationSnapshot = $invoice->party_snapshot;

    expect($creationSnapshot['name'])->toBe('مشتری فروش')
        ->and($creationSnapshot['party_version'])->toBe(0)
        ->and($invoice->partyIdentity()['legacy_current'] ?? false)->toBeFalse();

    $context['party']->update([
        'name' => 'نام تغییرکرده بعد از پیش‌نویس',
        'version' => 1,
    ]);

    $confirmed = app(SalesService::class)->confirm($invoice, $context['warehouse']->id, $context['manager']);

    expect($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and($confirmed->party_snapshot)->toBe($creationSnapshot)
        ->and($confirmed->partyIdentity()['name'])->toBe('مشتری فروش')
        ->and($confirmed->partyIdentity()['party_version'])->toBe(0);
});

it('updates a sales draft atomically while preserving its identity and creation snapshot', function () {
    $context = createSalesContext();
    $replacement = Product::query()->create([
        'code' => 'SALES-EDIT-P-002',
        'name' => 'کالای جایگزین ویرایش فروش',
        'purchase_price' => 120,
        'sale_price' => 200,
        'tax_rate' => 9,
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $replacement->id,
        quantity: 10,
        unitCost: 120,
        movementDate: '2026-05-01',
        idempotencyKey: 'sales-edit-replacement-opening',
        movementType: InventoryMovementType::Opening,
    );
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
        'sales-draft-edit-key',
    );
    $invoiceId = $invoice->id;
    $invoiceNumber = $invoice->invoice_number;
    $creationSnapshot = $invoice->party_snapshot;
    $originalHash = $invoice->idempotency_request_hash;
    $movementCount = InventoryMovement::query()->count();
    $journalCount = JournalEntry::query()->count();
    $context['party']->update([
        'name' => 'نام مشتری پس از ایجاد پیش‌نویس',
        'version' => 1,
    ]);

    $updated = $service->updateDraft(
        $invoice,
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_number' => 'MUST-NOT-CHANGE',
            'idempotency_key' => 'must-not-change',
            'party_snapshot' => ['name' => 'MUST-NOT-CHANGE'],
            'invoice_date' => '2026-05-03',
            'due_date' => '2026-05-20',
            'notes' => 'پیش‌نویس ویرایش‌شده فروش',
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $replacement->id,
            'quantity' => 3,
            'unit_price' => 200,
            'discount_amount' => 20,
            'tax_rate' => 9,
        ]],
    );

    expect($updated->id)->toBe($invoiceId)
        ->and($updated->invoice_number)->toBe($invoiceNumber)
        ->and($updated->idempotency_key)->toBe('sales-draft-edit-key')
        ->and($updated->idempotency_request_hash)->not->toBe($originalHash)
        ->and($updated->party_snapshot)->toBe($creationSnapshot)
        ->and($updated->invoice_date->toDateString())->toBe('2026-05-03')
        ->and($updated->due_date?->toDateString())->toBe('2026-05-20')
        ->and($updated->notes)->toBe('پیش‌نویس ویرایش‌شده فروش')
        ->and($updated->status)->toBe(InvoiceStatus::Draft)
        ->and($updated->type)->toBe(InvoiceType::Sales)
        ->and($updated->subtotal)->toBe('600.00')
        ->and($updated->discount_total)->toBe('20.00')
        ->and($updated->tax_total)->toBe('52.20')
        ->and($updated->total)->toBe('632.20')
        ->and($updated->items)->toHaveCount(1)
        ->and($updated->items->first()->product_id)->toBe($replacement->id)
        ->and($updated->items->first()->product_code)->toBe($replacement->code)
        ->and($updated->items->first()->product_name)->toBe($replacement->name)
        ->and($updated->items->first()->quantity)->toBe('3.000')
        ->and($updated->items->first()->discount_amount)->toBe('20.00')
        ->and($updated->items->first()->tax_rate)->toBe('9.00')
        ->and($updated->items->first()->tax_amount)->toBe('52.20')
        ->and($updated->items->first()->line_total)->toBe('632.20')
        ->and($updated->items->first()->sort_order)->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->count())->toBe($movementCount)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(InventoryMovement::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoiceId)
            ->count())->toBe(0)
        ->and(JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoiceId)
            ->count())->toBe(0);

    $confirmed = $service->confirm($updated, $context['warehouse']->id, $context['manager']);
    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $replacement->id)
        ->firstOrFail();
    $journal = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $invoiceId)
        ->firstOrFail();

    expect($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and($confirmed->party_snapshot)->toBe($creationSnapshot)
        ->and($confirmed->total)->toBe('632.20')
        ->and($confirmed->items->first()->total_cost_snapshot)->toBe('360.00')
        ->and($balance->quantity)->toBe('7.000')
        ->and($journal->totalDebit())->toBe('992.20')
        ->and($journal->totalCredit())->toBe('992.20')
        ->and(InventoryMovement::query()->count())->toBe($movementCount + 1)
        ->and(JournalEntry::query()->count())->toBe($journalCount + 1);
});

it('recaptures the party snapshot when an edited draft changes customers', function () {
    $context = createSalesContext();
    $newParty = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SALES-EDIT-NEW-CUSTOMER',
        'name' => 'مشتری جدید ویرایش فروش',
        'phone' => '09121230000',
        'version' => 4,
        'is_active' => true,
    ]);
    $newCustomer = Customer::query()->create([
        'party_id' => $newParty->id,
        'credit_limit' => 20_000_000,
        'is_active' => true,
    ]);
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
        'sales-draft-customer-change-key',
    );
    $invoiceNumber = $invoice->invoice_number;
    $creationSnapshot = $invoice->party_snapshot;
    $originalHash = $invoice->idempotency_request_hash;
    $attributes = [
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'customer_id' => $newCustomer->id,
        'invoice_date' => '2026-05-03',
        'party_snapshot' => ['name' => 'MUST-NOT-USE-CLIENT-SNAPSHOT'],
    ];
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => 2,
        'unit_price' => 200,
        'tax_rate' => 10,
    ]];

    $updated = $service->updateDraft($invoice, $attributes, $items);
    $newCustomerSnapshot = $updated->party_snapshot;

    expect($updated->customer_id)->toBe($newCustomer->id)
        ->and($updated->invoice_number)->toBe($invoiceNumber)
        ->and($updated->idempotency_key)->toBe('sales-draft-customer-change-key')
        ->and($updated->idempotency_request_hash)->not->toBe($originalHash)
        ->and($newCustomerSnapshot)->not->toBe($creationSnapshot)
        ->and($newCustomerSnapshot['party_id'])->toBe($newParty->id)
        ->and($newCustomerSnapshot['name'])->toBe('مشتری جدید ویرایش فروش')
        ->and($newCustomerSnapshot['phone'])->toBe('09121230000')
        ->and($newCustomerSnapshot['party_version'])->toBe(4);

    $newParty->update([
        'name' => 'نام مشتری جدید پس از ویرایش',
        'version' => 5,
    ]);
    $updatedAgain = $service->updateDraft(
        $updated,
        array_replace($attributes, ['notes' => 'ویرایش دوم با مشتری بدون تغییر']),
        $items,
    );

    expect($updatedAgain->party_snapshot)->toBe($newCustomerSnapshot)
        ->and($updatedAgain->party_snapshot['name'])->toBe('مشتری جدید ویرایش فروش')
        ->and($updatedAgain->party_snapshot['party_version'])->toBe(4);
});

it('rejects editing a confirmed sales invoice or an invoice of another type', function () {
    $context = createSalesContext();
    $service = app(SalesService::class);
    $invoice = createSalesInvoice($context, 1);
    $attributes = [
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'customer_id' => $context['customer']->id,
        'invoice_date' => '2026-05-02',
    ];
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => 2,
        'unit_price' => 200,
        'tax_rate' => 10,
    ]];
    $confirmedItemId = $invoice->items->firstOrFail()->id;

    expect(fn () => $service->updateDraft($invoice, $attributes, $items))
        ->toThrow(DomainException::class);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and($invoice->fresh()->total)->toBe('220.00')
        ->and($invoice->fresh()->items->firstOrFail()->id)->toBe($confirmedItemId)
        ->and(InventoryMovement::query()->count())->toBe(2)
        ->and(JournalEntry::query()->count())->toBe(1);

    $salesReturn = Invoice::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'invoice_number' => 'SALES-RETURN-NOT-EDITABLE',
        'type' => InvoiceType::SalesReturn,
        'invoice_date' => '2026-05-02',
        'customer_id' => $context['customer']->id,
        'supplier_id' => null,
    ]);

    expect(fn () => $service->updateDraft($salesReturn, $attributes, $items))
        ->toThrow(DomainException::class);
});

it('rejects sales draft scope changes and inactive or out-of-scope customers', function () {
    $context = createSalesContext();
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
    $attributes = [
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'customer_id' => $context['customer']->id,
        'invoice_date' => '2026-05-03',
    ];
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => 2,
        'unit_price' => 200,
        'tax_rate' => 10,
    ]];
    $otherStore = Store::query()->create([
        'code' => 'OTHER-SALES-EDIT-STORE',
        'name' => 'فروشگاه دیگر ویرایش فروش',
        'is_active' => true,
    ]);
    $otherBranch = $context['store']->branches()->create([
        'code' => 'OTHER-SALES-EDIT-BRANCH',
        'name' => 'شعبه دیگر ویرایش فروش',
    ]);

    foreach ([
        'store_id' => $otherStore->id,
        'branch_id' => $otherBranch->id,
        'fiscal_year_id' => $context['fiscalYear']->id + 1000,
    ] as $field => $value) {
        expect(fn () => $service->updateDraft(
            $invoice,
            array_replace($attributes, [$field => $value]),
            $items,
        ))->toThrow(DomainException::class, 'دامنه فاکتور فروش قابل تغییر نیست.');
    }

    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'OTHER-SALES-EDIT-CUSTOMER',
        'name' => 'مشتری فروشگاه دیگر',
        'is_active' => true,
    ]);
    $otherCustomer = Customer::query()->create([
        'party_id' => $otherParty->id,
        'is_active' => true,
    ]);

    expect(fn () => $service->updateDraft(
        $invoice,
        array_replace($attributes, ['customer_id' => $otherCustomer->id]),
        $items,
    ))->toThrow(DomainException::class, 'مشتری باید متعلق به فروشگاه فاکتور باشد.');

    $context['customer']->update(['is_active' => false]);

    expect(fn () => $service->updateDraft($invoice, $attributes, $items))
        ->toThrow(DomainException::class, 'مشتری انتخاب‌شده فعال نیست.');

    $context['customer']->update(['is_active' => true]);
    $context['party']->update(['is_active' => false]);

    expect(fn () => $service->updateDraft($invoice, $attributes, $items))
        ->toThrow(DomainException::class, 'مشتری انتخاب‌شده فعال نیست.');

    expect($invoice->fresh()->total)->toBe('220.00')
        ->and($invoice->fresh()->customer_id)->toBe($context['customer']->id)
        ->and($invoice->fresh()->items)->toHaveCount(1);
});

it('rejects moving a sales draft into a closed accounting period', function () {
    $context = createSalesContext();
    $periods = AccountingPeriod::query()
        ->where('fiscal_year_id', $context['fiscalYear']->id)
        ->orderBy('period_number')
        ->get();
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $periods[2]->starts_on->toDateString(),
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
    $originalHash = $invoice->idempotency_request_hash;
    app(PeriodClosingService::class)->closePeriod(
        $context['store'],
        $periods[0],
        AccountingPeriodStatus::Closed,
        $context['manager'],
        'بستن دوره اول برای آزمون ویرایش فروش',
    );
    app(PeriodClosingService::class)->closePeriod(
        $context['store'],
        $periods[1],
        AccountingPeriodStatus::SoftClosed,
        $context['manager'],
        'بستن دوره هدف برای آزمون ویرایش فروش',
    );

    expect(fn () => $service->updateDraft(
        $invoice,
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $periods[1]->starts_on->toDateString(),
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 2,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    ))->toThrow(DomainException::class);

    expect($invoice->fresh()->invoice_date->toDateString())
        ->toBe($periods[2]->starts_on->toDateString())
        ->and($invoice->fresh()->total)->toBe('220.00')
        ->and($invoice->fresh()->idempotency_request_hash)->toBe($originalHash);
});

it('rolls back replaced sales lines when an edited product is inactive', function () {
    $context = createSalesContext();
    $inactive = Product::query()->create([
        'code' => 'SALES-EDIT-INACTIVE',
        'name' => 'کالای غیرفعال ویرایش فروش',
        'purchase_price' => 100,
        'sale_price' => 150,
        'is_active' => false,
    ]);
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
    $originalHash = $invoice->idempotency_request_hash;
    $originalItemId = $invoice->items->firstOrFail()->id;

    expect(fn () => $service->updateDraft(
        $invoice,
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-03',
        ],
        [[
            'product_id' => $inactive->id,
            'quantity' => 2,
            'unit_price' => 150,
            'tax_rate' => 10,
        ]],
    ))->toThrow(DomainException::class);

    expect($invoice->fresh()->total)->toBe('220.00')
        ->and($invoice->fresh()->idempotency_request_hash)->toBe($originalHash)
        ->and($invoice->fresh()->items->firstOrFail()->id)->toBe($originalItemId)
        ->and($invoice->fresh()->items->firstOrFail()->product_id)
        ->toBe($context['product']->id);
});

it('allocates one customer receipt across multiple sales invoices', function () {
    $context = createSalesContext();
    $invoiceA = createSalesInvoice($context, 4);
    $invoiceB = createSalesInvoice($context, 1, '2026-05-03');
    $receiptService = app(CustomerReceiptService::class);
    $receipt = $receiptService->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-04',
        paymentMethod: PaymentMethod::Cash,
        allocations: [
            ['invoice_id' => $invoiceA->id, 'amount' => 300],
            ['invoice_id' => $invoiceB->id, 'amount' => 100],
        ],
        approver: $context['manager'],
        idempotencyKey: 'receipt-multi-001',
    );

    expect($receipt->amount)->toBe('400.00')
        ->and($receipt->allocations)->toHaveCount(2)
        ->and($invoiceA->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoiceB->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(JournalEntry::query()->where('source_type', Receipt::class)->count())->toBe(1);

    $duplicate = $receiptService->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-04',
        paymentMethod: PaymentMethod::Cash,
        allocations: [
            ['invoice_id' => $invoiceA->id, 'amount' => 300],
            ['invoice_id' => $invoiceB->id, 'amount' => 100],
        ],
        approver: $context['manager'],
        idempotencyKey: 'receipt-multi-001',
    );

    expect($duplicate->is($receipt))->toBeTrue()
        ->and(Receipt::query()->count())->toBe(1);
});

it('confirms a sales return, restores inventory, and reverses revenue and COGS', function () {
    $context = createSalesContext();
    $invoice = createSalesInvoice($context, 4);
    $returnService = app(SalesReturnService::class);
    $return = $returnService->createDraft(
        $invoice,
        [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => 1]],
        '2026-05-04',
        $context['manager']->id,
    );
    $confirmed = $returnService->confirm($return, $context['warehouse']->id, $context['manager']);
    $balance = InventoryBalance::query()->where('product_id', $context['product']->id)->firstOrFail();
    $journal = JournalEntry::query()->where('source_type', Invoice::class)->where('source_id', $confirmed->id)->firstOrFail();

    expect($confirmed->type)->toBe(InvoiceType::SalesReturn)
        ->and($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and($confirmed->total)->toBe('220.00')
        ->and($balance->quantity)->toBe('7.000')
        ->and($balance->inventory_value)->toBe('700.00')
        ->and($journal->totalDebit())->toBe('320.00')
        ->and($journal->totalCredit())->toBe('320.00');
});

it('allows a historical sales return after the customer, party, and product are deactivated', function () {
    $context = createSalesContext();
    $invoice = createSalesInvoice($context, 2);
    $context['customer']->update(['is_active' => false]);
    $context['party']->update(['is_active' => false]);
    $context['product']->update(['is_active' => false]);
    $items = [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => 1]];

    $return = app(SalesReturnService::class)->createDraft(
        $invoice,
        $items,
        '2026-05-04',
        $context['manager']->id,
        'inactive-sales-return',
    );
    $retry = app(SalesReturnService::class)->createDraft(
        $invoice,
        $items,
        '2026-05-04',
        $context['manager']->id,
        'inactive-sales-return',
    );
    $confirmed = app(SalesReturnService::class)->confirm(
        $return,
        $context['warehouse']->id,
        $context['manager'],
    );

    expect($retry->getKey())->toBe($return->getKey())
        ->and($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and(JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $confirmed->id)
            ->firstOrFail()->status)->toBe(JournalEntryStatus::Posted);
});

it('still rejects a new sale for a deactivated customer or party', function () {
    $context = createSalesContext();
    $context['customer']->update(['is_active' => false]);
    $context['party']->update(['is_active' => false]);

    app(SalesService::class)->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-03',
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
})->throws(DomainException::class);

it('blocks editing or deleting finalized sales documents', function () {
    $context = createSalesContext();
    $invoice = createSalesInvoice($context, 1);

    expect(fn () => $invoice->update(['notes' => 'ویرایش غیرمجاز']))
        ->toThrow(DomainException::class);

    expect(fn () => $invoice->items->first()->update(['quantity' => 2]))
        ->toThrow(DomainException::class);

    expect(fn () => $invoice->delete())
        ->toThrow(DomainException::class);
});

it('rejects a sale above the customer credit limit', function () {
    $context = createSalesContext();
    $context['customer']->update(['credit_limit' => 100]);
    $service = app(SalesService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );

    expect(fn () => $service->confirm($invoice, $context['warehouse']->id, $context['manager']))
        ->toThrow(DomainException::class)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('rejects a sales return dated outside the original fiscal year', function () {
    $context = createSalesContext();
    $invoice = createSalesInvoice($context, 1);

    app(SalesReturnService::class)->createDraft(
        $invoice,
        [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => 1]],
        '2027-03-21',
        $context['manager']->id,
    );
})->throws(DomainException::class);

it('rejects settling an invoice from another branch', function () {
    $context = createSalesContext();
    $invoice = createSalesInvoice($context, 1);
    $otherBranch = $context['store']->branches()->create([
        'code' => 'OTHER-SALES-BRANCH',
        'name' => 'شعبه دیگر فروش',
    ]);

    app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $otherBranch->id,
        fiscalYearId: $context['fiscalYear']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-04',
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $invoice->id, 'amount' => 100]],
        approver: $context['manager'],
        idempotencyKey: 'wrong-branch-receipt',
    );
})->throws(DomainException::class);
