<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use Database\Seeders\AccountSeeder;

function createReturnPaymentContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()
        ->where('branch_id', $branch->id)
        ->where('is_default', true)
        ->firstOrFail();
    $fiscalYear = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('title', '1405')
        ->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'RETURN-PAY-PARTY',
        'name' => 'تأمین‌کننده برگشت و پرداخت',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
    ]);
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();

    return compact('store', 'branch', 'warehouse', 'fiscalYear', 'party', 'supplier', 'manager');
}

function createConfirmedPurchase(
    array $context,
    Product $product,
    string $number,
    string|int|float $quantity,
    string|int|float $unitPrice,
): Invoice {
    $service = app(PurchaseService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-05-01',
            'created_by' => $context['manager']->id,
        ],
        [
            [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => 10,
            ],
        ],
    );

    return $service->confirm($invoice, $context['warehouse']->id, $context['manager']);
}

it('confirms a partial purchase return and updates inventory and payable', function () {
    $context = createReturnPaymentContext();
    $product = Product::query()->create([
        'code' => 'RETURN-P-001',
        'name' => 'کالای برگشتی',
        'purchase_price' => 100_000,
        'sale_price' => 150_000,
    ]);
    $purchase = createConfirmedPurchase($context, $product, 'PUR-RET-001', 10, 100_000);
    $returnService = app(PurchaseReturnService::class);
    $return = $returnService->createDraft(
        $purchase,
        [
            [
                'invoice_item_id' => $purchase->items->first()->id,
                'quantity' => 4,
            ],
        ],
        '2026-05-02',
        $context['manager']->id,
    );
    $confirmed = $returnService->confirm($return, $context['warehouse']->id, $context['manager']);
    $balance = InventoryBalance::query()->where('product_id', $product->id)->firstOrFail();
    $returnJournal = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $confirmed->id)
        ->firstOrFail();

    expect($confirmed->type)->toBe(InvoiceType::PurchaseReturn)
        ->and($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and($confirmed->total)->toBe('440000.00')
        ->and($balance->quantity)->toBe('6.000')
        ->and($balance->inventory_value)->toBe('600000.00')
        ->and($returnJournal->totalDebit())->toBe('440000.00')
        ->and($returnJournal->totalCredit())->toBe('440000.00');
});

it('rejects returning more than the remaining purchased quantity', function () {
    $context = createReturnPaymentContext();
    $product = Product::query()->create([
        'code' => 'RETURN-P-002',
        'name' => 'کالای برگشت بیش از حد',
        'purchase_price' => 100_000,
    ]);
    $purchase = createConfirmedPurchase($context, $product, 'PUR-RET-002', 5, 100_000);
    $returnService = app(PurchaseReturnService::class);
    $first = $returnService->createDraft(
        $purchase,
        [['invoice_item_id' => $purchase->items->first()->id, 'quantity' => 3]],
        '2026-05-02',
        $context['manager']->id,
    );
    $returnService->confirm($first, $context['warehouse']->id, $context['manager']);

    $returnService->createDraft(
        $purchase,
        [['invoice_item_id' => $purchase->items->first()->id, 'quantity' => 3]],
        '2026-05-03',
        $context['manager']->id,
    );
})->throws(DomainException::class);

it('books a positive purchase-return price difference as credit', function () {
    $context = createReturnPaymentContext();
    $product = Product::query()->create([
        'code' => 'RETURN-DIFF-001',
        'name' => 'کالای اختلاف برگشت',
        'purchase_price' => 100,
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $product->id,
        quantity: 10,
        unitCost: 50,
        movementDate: '2026-04-30',
        idempotencyKey: 'return-difference-opening',
        movementType: InventoryMovementType::Opening,
    );
    $purchase = createConfirmedPurchase($context, $product, 'PUR-RET-DIFF', 1, 100);
    $returns = app(PurchaseReturnService::class);
    $return = $returns->createDraft(
        $purchase,
        [[
            'invoice_item_id' => $purchase->items->firstOrFail()->id,
            'quantity' => 1,
        ]],
        '2026-05-02',
        $context['manager']->id,
    );
    $confirmed = $returns->confirm(
        $return,
        $context['warehouse']->id,
        $context['manager'],
    );
    $journal = JournalEntry::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $confirmed->id)
        ->with('lines.account')
        ->firstOrFail();
    $difference = $journal->lines->first(
        fn ($line): bool => $line->account->code === '5106',
    );

    expect($difference)->not->toBeNull()
        ->and($difference->debit)->toBe('0.00')
        ->and($difference->credit)->toBe('45.45')
        ->and($journal->totalDebit())->toBe($journal->totalCredit());
});

it('allocates multiple payments across purchase invoices until paid', function () {
    $context = createReturnPaymentContext();
    $productA = Product::query()->create([
        'code' => 'PAY-P-001',
        'name' => 'کالای پرداخت الف',
        'purchase_price' => 100_000,
    ]);
    $productB = Product::query()->create([
        'code' => 'PAY-P-002',
        'name' => 'کالای پرداخت ب',
        'purchase_price' => 100_000,
    ]);
    $invoiceA = createConfirmedPurchase($context, $productA, 'PUR-PAY-001', 10, 100_000);
    $invoiceB = createConfirmedPurchase($context, $productB, 'PUR-PAY-002', 2, 100_000);
    $returnService = app(PurchaseReturnService::class);
    $return = $returnService->createDraft(
        $invoiceA,
        [['invoice_item_id' => $invoiceA->items->first()->id, 'quantity' => 4]],
        '2026-05-02',
        $context['manager']->id,
    );
    $returnService->confirm($return, $context['warehouse']->id, $context['manager']);
    $paymentService = app(SupplierPaymentService::class);

    $firstPayment = $paymentService->pay(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        supplierId: $context['supplier']->id,
        paymentDate: '2026-05-04',
        paymentMethod: PaymentMethod::Cash,
        allocations: [
            ['invoice_id' => $invoiceA->id, 'amount' => 300_000],
            ['invoice_id' => $invoiceB->id, 'amount' => 100_000],
        ],
        approver: $context['manager'],
        idempotencyKey: 'supplier-payment-001',
    );

    expect($firstPayment->amount)->toBe('400000.00')
        ->and($firstPayment->allocations)->toHaveCount(2)
        ->and($invoiceA->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoiceB->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid);

    $secondPayment = $paymentService->pay(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        supplierId: $context['supplier']->id,
        paymentDate: '2026-05-05',
        paymentMethod: PaymentMethod::Cash,
        allocations: [
            ['invoice_id' => $invoiceA->id, 'amount' => 360_000],
            ['invoice_id' => $invoiceB->id, 'amount' => 120_000],
        ],
        approver: $context['manager'],
        idempotencyKey: 'supplier-payment-002',
    );

    expect($secondPayment->amount)->toBe('480000.00')
        ->and($invoiceA->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoiceB->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::query()->count())->toBe(2)
        ->and(JournalEntry::query()->where('source_type', Payment::class)->count())->toBe(2);
});
