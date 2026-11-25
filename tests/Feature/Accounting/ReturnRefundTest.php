<?php

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\ReturnRefund;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPayableService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Returns\ReturnRefundService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\CustomerReceivableService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;

function returnRefundContext(): array
{
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'REFUND-CUSTOMER',
        'name' => 'Customer',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'REFUND-SUPPLIER',
        'name' => 'Supplier',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'REFUND-PRODUCT',
        'name' => 'Refund product',
        'purchase_price' => 100,
        'sale_price' => 100,
        'tax_rate' => 0,
        'item_type' => 'stock',
    ]);
    app(InventoryService::class)->receive(
        $warehouse->id,
        $product->id,
        10,
        100,
        $year->starts_on->toDateString(),
        'refund-opening',
        InventoryMovementType::Opening,
    );
    $cash = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'cash')->firstOrFail();

    return compact(
        'store',
        'branch',
        'warehouse',
        'year',
        'manager',
        'customer',
        'supplier',
        'product',
        'cash',
    );
}

it('shows paid sales returns as credit and clears it through a customer refund', function () {
    $context = returnRefundContext();
    $date = $context['year']->starts_on->toDateString();
    $sales = app(SalesService::class);
    $invoice = $sales->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $date,
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'refund-sale',
    );
    $sales->confirm($invoice, $context['warehouse']->id, $context['manager']);
    app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        customerId: $context['customer']->id,
        receiptDate: $date,
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $invoice->id, 'amount' => '100']],
        approver: $context['manager'],
        idempotencyKey: 'refund-sale-receipt',
        treasuryAccountId: $context['cash']->id,
    );
    $returns = app(SalesReturnService::class);
    $return = $returns->createDraft(
        $invoice,
        [[
            'invoice_item_id' => $invoice->items()->firstOrFail()->id,
            'quantity' => 1,
        ]],
        $date,
        $context['manager']->id,
        null,
        'refund-sale-return',
    );
    $returns->confirm($return, $context['warehouse']->id, $context['manager']);
    $receivables = app(CustomerReceivableService::class);

    expect($receivables->outstandingForInvoice($invoice))->toBe('-100.00')
        ->and($receivables->customerOutstanding($context['customer']))->toBe('-100.00');

    $refund = app(ReturnRefundService::class)->refund(
        $return,
        $context['cash']->id,
        $date,
        '100',
        $context['manager'],
        'customer-refund',
    );

    expect($refund->status)->toBe('posted')
        ->and($refund->journalEntry->totalDebit())->toBe('100.00')
        ->and($refund->journalEntry->totalCredit())->toBe('100.00')
        ->and($receivables->outstandingForInvoice($invoice))->toBe('0.00')
        ->and($receivables->customerOutstanding($context['customer']))->toBe('0.00');

    $retry = app(ReturnRefundService::class)->refund(
        $return,
        $context['cash']->id,
        $date,
        '100',
        $context['manager'],
        'customer-refund',
    );
    expect($retry->id)->toBe($refund->id)
        ->and(ReturnRefund::query()->count())->toBe(1);
});

it('shows paid purchase returns as supplier credit and clears it through a refund', function () {
    $context = returnRefundContext();
    $date = $context['year']->starts_on->toDateString();
    $purchases = app(PurchaseService::class);
    $invoice = $purchases->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => $date,
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'refund-purchase',
    );
    $purchases->confirm($invoice, $context['warehouse']->id, $context['manager']);
    app(SupplierPaymentService::class)->pay(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        supplierId: $context['supplier']->id,
        paymentDate: $date,
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $invoice->id, 'amount' => '100']],
        approver: $context['manager'],
        idempotencyKey: 'refund-purchase-payment',
        treasuryAccountId: $context['cash']->id,
    );
    $returns = app(PurchaseReturnService::class);
    $return = $returns->createDraft(
        $invoice,
        [[
            'invoice_item_id' => $invoice->items()->firstOrFail()->id,
            'quantity' => 1,
        ]],
        $date,
        $context['manager']->id,
        null,
        'refund-purchase-return',
    );
    $returns->confirm($return, $context['warehouse']->id, $context['manager']);
    $payables = app(SupplierPayableService::class);

    expect($payables->outstandingForInvoice($invoice))->toBe('-100.00')
        ->and($payables->supplierOutstanding($context['supplier']))->toBe('-100.00');

    app(ReturnRefundService::class)->refund(
        $return,
        $context['cash']->id,
        $date,
        '100',
        $context['manager'],
        'supplier-refund',
    );

    expect($payables->outstandingForInvoice($invoice))->toBe('0.00')
        ->and($payables->supplierOutstanding($context['supplier']))->toBe('0.00');
});
