<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
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
        ->and($journal->totalCredit())->toBe('1280.00');
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
