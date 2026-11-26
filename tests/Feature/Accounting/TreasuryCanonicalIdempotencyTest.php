<?php

use App\Enums\CheckType;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use App\Services\Treasury\CheckRegistrationService;
use App\Services\Treasury\CheckSettlementService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function b33TreasuryContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()
        ->where('branch_id', $branch->id)
        ->where('is_default', true)
        ->firstOrFail();
    $year = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('title', '1405')
        ->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $otherUser = User::factory()->create(['role' => UserRole::Manager]);
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'B33-CUSTOMER',
        'name' => 'B33 customer',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => '1000000.00',
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'B33-SUPPLIER',
        'name' => 'B33 supplier',
    ]);
    $supplier = Supplier::query()->create(['party_id' => $supplierParty->id]);
    $product = Product::query()->create([
        'code' => 'B33-PRODUCT',
        'name' => 'B33 product',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 0,
    ]);
    $cash = TreasuryAccount::query()
        ->where('branch_id', $branch->id)
        ->where('type', TreasuryAccountType::Cash)
        ->firstOrFail();
    $bank = TreasuryAccount::query()
        ->where('branch_id', $branch->id)
        ->where('type', TreasuryAccountType::Bank)
        ->firstOrFail();

    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 20,
        unitCost: 100,
        movementDate: '2026-05-01',
        idempotencyKey: 'b33-opening',
        movementType: InventoryMovementType::Opening,
    );

    return compact(
        'store',
        'branch',
        'warehouse',
        'year',
        'manager',
        'otherUser',
        'customerParty',
        'customer',
        'supplierParty',
        'supplier',
        'product',
        'cash',
        'bank',
    );
}

/** @param array<string, mixed> $context */
function b33SalesInvoice(array $context, string $number, int $quantity = 5): Invoice
{
    $service = app(SalesService::class);
    $draft = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => '2026-05-02',
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => $quantity,
            'unit_price' => 200,
            'tax_rate' => 0,
        ]],
        $number,
    );

    return $service->confirm($draft, $context['warehouse']->id, $context['manager']);
}

/** @param array<string, mixed> $context */
function b33PurchaseInvoice(array $context, string $number, int $quantity = 5): Invoice
{
    $service = app(PurchaseService::class);
    $draft = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['year']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-02',
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => $quantity,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]],
        $number,
    );

    return $service->confirm($draft, $context['warehouse']->id, $context['manager']);
}

/** @param array<string, mixed> $context */
function b33CheckReceipt(array $context, string $number): array
{
    $check = app(CheckRegistrationService::class)->register(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        type: CheckType::Receivable,
        partyProfileId: $context['customer']->id,
        checkNumber: $number,
        bankName: 'B33 bank',
        branchName: 'Central',
        amount: 100,
        issueDate: '2026-05-02',
        dueDate: '2026-05-10',
        creator: $context['manager'],
        idempotencyKey: (string) Str::uuid(),
        description: 'B33 check',
        sourceInvoiceIds: [$context['salesInvoice']->id],
    );
    $receipt = app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-03',
        paymentMethod: PaymentMethod::Check,
        allocations: [['invoice_id' => $context['salesInvoice']->id, 'amount' => 100]],
        approver: $context['manager'],
        idempotencyKey: 'b33-receipt-'.$number,
        checkId: $check->id,
        reference: 'B33-REF-'.$number,
        description: 'B33 receipt',
    );

    return [$check, $receipt];
}

it('canonicalizes sales return retries and compares persisted state for legacy null hashes', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-1');
    $service = app(SalesReturnService::class);
    $items = [['invoice_item_id' => $context['salesInvoice']->items->firstOrFail()->id, 'quantity' => 1]];
    $create = fn (User $actor, array $requestedItems, string $date) => $service->createDraft(
        $context['salesInvoice'],
        $requestedItems,
        $date,
        $actor->id,
        'b33-sales-return',
    );

    $first = $create($context['manager'], $items, '2026-05-04');
    $context['customer']->update(['is_active' => false]);
    $context['customerParty']->update(['is_active' => false]);
    $context['product']->update(['is_active' => false]);

    expect($first->idempotency_request_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($create($context['manager'], $items, '2026-05-04')->is($first))->toBeTrue()
        ->and(fn () => $create($context['otherUser'], $items, '2026-05-04'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $create($context['manager'], [['invoice_item_id' => $items[0]['invoice_item_id'], 'quantity' => 2]], '2026-05-04'))
        ->toThrow(DomainException::class, 'متفاوت');

    DB::table('invoices')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($create($context['manager'], $items, '2026-05-04')->is($first))->toBeTrue()
        ->and(fn () => $create($context['manager'], $items, '2026-05-05'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $create($context['otherUser'], $items, '2026-05-04'))
        ->toThrow(DomainException::class, 'متفاوت');

    $updated = $service->updateDraft(
        $first,
        [['invoice_item_id' => $items[0]['invoice_item_id'], 'quantity' => 2]],
        '2026-05-04',
    );

    expect($updated->idempotency_request_hash)->not->toBeNull()
        ->and(fn () => $create($context['manager'], $items, '2026-05-04'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and($create(
            $context['manager'],
            [['invoice_item_id' => $items[0]['invoice_item_id'], 'quantity' => 2]],
            '2026-05-04',
        )->is($updated))->toBeTrue()
        ->and(Invoice::query()->where('type', 'sales_return')->count())->toBe(1);
});

it('canonicalizes purchase return notes items and actor with legacy persisted comparison', function () {
    $context = b33TreasuryContext();
    $context['purchaseInvoice'] = b33PurchaseInvoice($context, 'B33-PURCHASE-1');
    $service = app(PurchaseReturnService::class);
    $items = [['invoice_item_id' => $context['purchaseInvoice']->items->firstOrFail()->id, 'quantity' => 1]];
    $create = fn (User $actor, ?string $notes, array $requestedItems = []) => $service->createDraft(
        $context['purchaseInvoice'],
        $requestedItems === [] ? $items : $requestedItems,
        '2026-05-04',
        $actor->id,
        $notes,
        'b33-purchase-return',
    );

    $first = $create($context['manager'], 'Original notes');
    $context['supplier']->update(['is_active' => false]);
    $context['supplierParty']->update(['is_active' => false]);
    $context['product']->update(['is_active' => false]);

    expect($create($context['manager'], 'Original notes')->is($first))->toBeTrue()
        ->and(fn () => $create($context['manager'], 'Changed notes'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $create($context['otherUser'], 'Original notes'))
        ->toThrow(DomainException::class, 'متفاوت');

    DB::table('invoices')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($create($context['manager'], 'Original notes')->is($first))->toBeTrue()
        ->and(fn () => $create($context['manager'], 'Changed notes'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $create($context['manager'], 'Original notes', [
            ['invoice_item_id' => $items[0]['invoice_item_id'], 'quantity' => 2],
        ]))->toThrow(DomainException::class, 'متفاوت')
        ->and(Invoice::query()->where('type', 'purchase_return')->count())->toBe(1);
});

it('canonicalizes receipt scope actor account allocations reference and legacy null hashes', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-RECEIPT');
    $otherInvoice = b33SalesInvoice($context, 'B33-SALE-RECEIPT-2', 1);
    $service = app(CustomerReceiptService::class);
    $receive = fn (User $actor, ?int $accountId, array $allocations, string $reference) => $service->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-04',
        paymentMethod: PaymentMethod::Cash,
        allocations: $allocations,
        approver: $actor,
        idempotencyKey: 'b33-receipt-explicit',
        treasuryAccountId: $accountId,
        reference: $reference,
        description: 'B33 receipt description',
    );
    $allocations = [['invoice_id' => $context['salesInvoice']->id, 'amount' => 100]];

    $first = $receive($context['manager'], $context['cash']->id, $allocations, 'ORIGINAL');
    $defaultReceipt = app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-04',
        paymentMethod: PaymentMethod::Cash,
        allocations: $allocations,
        approver: $context['manager'],
        idempotencyKey: 'b33-receipt-default',
        reference: 'DEFAULT',
    );
    $context['customer']->update(['is_active' => false]);
    $context['customerParty']->update(['is_active' => false]);
    $context['cash']->update(['is_active' => false]);

    expect($receive($context['manager'], $context['cash']->id, $allocations, 'ORIGINAL')->is($first))->toBeTrue()
        ->and(fn () => $receive($context['otherUser'], $context['cash']->id, $allocations, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $receive($context['manager'], $context['bank']->id, $allocations, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $receive($context['manager'], $context['cash']->id, [
            ['invoice_id' => $otherInvoice->id, 'amount' => 100],
        ], 'ORIGINAL'))->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $receive($context['manager'], $context['cash']->id, $allocations, 'CHANGED'))
        ->toThrow(DomainException::class, 'متفاوت');

    DB::table('receipts')->where('id', $first->id)->update(['idempotency_request_hash' => null]);
    DB::table('receipts')->where('id', $defaultReceipt->id)->update(['idempotency_request_hash' => null]);

    expect($receive($context['manager'], $context['cash']->id, $allocations, 'ORIGINAL')->is($first))->toBeTrue()
        ->and(fn () => $receive($context['manager'], $context['bank']->id, $allocations, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $receive($context['otherUser'], $context['cash']->id, $allocations, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(app(CustomerReceiptService::class)->receive(
            storeId: $context['store']->id,
            branchId: $context['branch']->id,
            fiscalYearId: $context['year']->id,
            customerId: $context['customer']->id,
            receiptDate: '2026-05-04',
            paymentMethod: PaymentMethod::Cash,
            allocations: $allocations,
            approver: $context['manager'],
            idempotencyKey: 'b33-receipt-default',
            reference: 'DEFAULT',
        )->is($defaultReceipt))->toBeTrue()
        ->and(Receipt::query()->count())->toBe(2);
});

it('canonicalizes supplier payment account actor allocations and legacy null hashes', function () {
    $context = b33TreasuryContext();
    $context['purchaseInvoice'] = b33PurchaseInvoice($context, 'B33-PURCHASE-PAYMENT');
    $service = app(SupplierPaymentService::class);
    $allocations = [['invoice_id' => $context['purchaseInvoice']->id, 'amount' => 100]];
    $pay = fn (User $actor, ?int $accountId, string $reference) => $service->pay(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        supplierId: $context['supplier']->id,
        paymentDate: '2026-05-04',
        paymentMethod: PaymentMethod::Bank,
        allocations: $allocations,
        approver: $actor,
        idempotencyKey: 'b33-payment',
        treasuryAccountId: $accountId,
        reference: $reference,
        description: 'B33 payment description',
    );

    $first = $pay($context['manager'], $context['bank']->id, 'ORIGINAL');
    $context['supplier']->update(['is_active' => false]);
    $context['supplierParty']->update(['is_active' => false]);
    $context['bank']->update(['is_active' => false]);

    expect($pay($context['manager'], $context['bank']->id, 'ORIGINAL')->is($first))->toBeTrue()
        ->and(fn () => $pay($context['otherUser'], $context['bank']->id, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $pay($context['manager'], $context['cash']->id, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $pay($context['manager'], $context['bank']->id, 'CHANGED'))
        ->toThrow(DomainException::class, 'متفاوت');

    DB::table('payments')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($pay($context['manager'], $context['bank']->id, 'ORIGINAL')->is($first))->toBeTrue()
        ->and(fn () => $pay($context['manager'], $context['cash']->id, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $pay($context['otherUser'], $context['bank']->id, 'ORIGINAL'))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(Payment::query()->count())->toBe(1);
});

it('persists check source invoices and rejects changed legacy actor or source context', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-CHECK');
    $otherInvoice = b33SalesInvoice($context, 'B33-SALE-CHECK-2', 1);
    $context['customer']->update(['is_active' => false]);
    $context['customerParty']->update(['is_active' => false]);
    $service = app(CheckRegistrationService::class);
    $idempotencyKey = (string) Str::uuid();
    $register = fn (User $actor, array $sourceInvoiceIds) => $service->register(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['year']->id,
        type: CheckType::Receivable,
        partyProfileId: $context['customer']->id,
        checkNumber: 'B33-CHECK-SOURCE',
        bankName: 'B33 bank',
        branchName: 'Central',
        amount: 100,
        issueDate: '2026-05-02',
        dueDate: '2026-05-10',
        creator: $actor,
        idempotencyKey: $idempotencyKey,
        description: 'B33 source check',
        sourceInvoiceIds: $sourceInvoiceIds,
    );

    $first = $register($context['manager'], [$context['salesInvoice']->id]);

    expect($first->source_invoice_ids)->toBe([$context['salesInvoice']->id])
        ->and($first->fiscal_year_id)->toBe($context['year']->id)
        ->and($register($context['manager'], [$context['salesInvoice']->id])->is($first))->toBeTrue()
        ->and(fn () => $register($context['manager'], [$otherInvoice->id]))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $register($context['otherUser'], [$context['salesInvoice']->id]))
        ->toThrow(DomainException::class, 'متفاوت');

    DB::table('checks')->where('id', $first->id)->update(['idempotency_request_hash' => null]);

    expect($register($context['manager'], [$context['salesInvoice']->id])->is($first))->toBeTrue()
        ->and(fn () => $register($context['manager'], [$otherInvoice->id]))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(fn () => $register($context['otherUser'], [$context['salesInvoice']->id]))
        ->toThrow(DomainException::class, 'متفاوت')
        ->and(Check::query()->count())->toBe(1);
});

it('canonicalizes check settlement before account revaluation and supports legacy null hashes', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-SETTLE');
    [$check] = b33CheckReceipt($context, 'B33-SETTLE-1');
    $otherBank = TreasuryAccount::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'gl_account_id' => $context['bank']->gl_account_id,
        'code' => 'B33-BANK-2',
        'name' => 'B33 second bank',
        'type' => TreasuryAccountType::Bank,
        'is_active' => true,
    ]);
    $service = app(CheckSettlementService::class);
    $settle = fn (User $actor, string $date, ?int $accountId) => $service->collectReceivable(
        $check,
        $date,
        $actor,
        $accountId,
    );

    $first = $settle($context['manager'], '2026-05-10', $context['bank']->id);
    $context['bank']->update(['is_active' => false]);
    $otherBank->update(['is_active' => false]);

    expect($first->settlement_request_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($settle($context['manager'], '2026-05-10', $context['bank']->id)->is($first))->toBeTrue()
        ->and(fn () => $settle($context['otherUser'], '2026-05-10', $context['bank']->id))
        ->toThrow(DomainException::class)
        ->and(fn () => $settle($context['manager'], '2026-05-11', $context['bank']->id))
        ->toThrow(DomainException::class)
        ->and(fn () => $settle($context['manager'], '2026-05-10', $otherBank->id))
        ->toThrow(DomainException::class);
});

it('canonicalizes check bounce actor and date with non-blind legacy null comparison', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-BOUNCE');
    [$check] = b33CheckReceipt($context, 'B33-BOUNCE-1');
    $service = app(CheckSettlementService::class);
    $bounce = fn (User $actor, string $date) => $service->bounce($check, $date, $actor);

    $first = $bounce($context['manager'], '2026-05-11');
    $context['customer']->update(['is_active' => false]);
    $context['customerParty']->update(['is_active' => false]);

    expect($first->bounce_request_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($bounce($context['manager'], '2026-05-11')->is($first))->toBeTrue()
        ->and(fn () => $bounce($context['otherUser'], '2026-05-11'))
        ->toThrow(DomainException::class)
        ->and(fn () => $bounce($context['manager'], '2026-05-12'))
        ->toThrow(DomainException::class);
});

it('compares a legacy null settlement hash against persisted state', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-SETTLE-LEGACY');
    [$check] = b33CheckReceipt($context, 'B33-SETTLE-LEGACY');
    $service = app(CheckSettlementService::class);
    $settled = $service->collectReceivable($check, '2026-05-10', $context['manager'], $context['bank']->id);

    DB::table('checks')->where('id', $settled->id)->update(['settlement_request_hash' => null]);

    expect(DB::table('checks')->where('id', $settled->id)->value('settlement_request_hash'))->toBeNull()
        ->and($service->collectReceivable(
            $check,
            '2026-05-10',
            $context['manager'],
            $context['bank']->id,
        )->is($settled))->toBeTrue()
        ->and(fn () => $service->collectReceivable(
            $check,
            '2026-05-10',
            $context['otherUser'],
            $context['bank']->id,
        ))->toThrow(DomainException::class)
        ->and(fn () => $service->collectReceivable(
            $check,
            '2026-05-10',
            $context['manager'],
            $context['cash']->id,
        ))->toThrow(DomainException::class)
        ->and(fn () => $service->collectReceivable(
            $check,
            '2026-05-11',
            $context['manager'],
            $context['bank']->id,
        ))->toThrow(DomainException::class);
});

it('compares a legacy null bounce hash against persisted state', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-BOUNCE-LEGACY');
    [$check] = b33CheckReceipt($context, 'B33-BOUNCE-LEGACY');
    $service = app(CheckSettlementService::class);
    $bounced = $service->bounce($check, '2026-05-11', $context['manager']);

    DB::table('checks')->where('id', $bounced->id)->update(['bounce_request_hash' => null]);

    expect(DB::table('checks')->where('id', $bounced->id)->value('bounce_request_hash'))->toBeNull()
        ->and($service->bounce($check, '2026-05-11', $context['manager'])->is($bounced))->toBeTrue()
        ->and(fn () => $service->bounce($check, '2026-05-11', $context['otherUser']))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->bounce($check, '2026-05-12', $context['manager']))
        ->toThrow(DomainException::class);
});

it('rejects a legacy null settlement hash when persisted entry state is missing', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-SETTLE-MISSING');
    [$check] = b33CheckReceipt($context, 'B33-SETTLE-MISSING');
    $settled = app(CheckSettlementService::class)->collectReceivable(
        $check,
        '2026-05-10',
        $context['manager'],
        $context['bank']->id,
    );

    DB::table('checks')->where('id', $settled->id)->update([
        'settlement_request_hash' => null,
        'settlement_entry_id' => null,
    ]);

    expect(fn () => app(CheckSettlementService::class)->collectReceivable(
        $check,
        '2026-05-10',
        $context['manager'],
        $context['bank']->id,
    ))->toThrow(DomainException::class);
});

it('rejects a legacy null bounce hash when persisted entry state is missing', function () {
    $context = b33TreasuryContext();
    $context['salesInvoice'] = b33SalesInvoice($context, 'B33-SALE-BOUNCE-MISSING');
    [$check] = b33CheckReceipt($context, 'B33-BOUNCE-MISSING');
    $bounced = app(CheckSettlementService::class)->bounce($check, '2026-05-11', $context['manager']);

    DB::table('checks')->where('id', $bounced->id)->update([
        'bounce_request_hash' => null,
        'bounce_entry_id' => null,
    ]);

    expect(fn () => app(CheckSettlementService::class)->bounce(
        $check,
        '2026-05-11',
        $context['manager'],
    ))->toThrow(DomainException::class);
});

it('keeps treasury idempotency context columns on migration rollback', function () {
    $migration = require database_path('migrations/2026_11_26_000011_add_treasury_idempotency_context.php');
    $migration->down();

    expect(Schema::hasColumn('checks', 'fiscal_year_id'))->toBeTrue()
        ->and(Schema::hasColumn('checks', 'source_invoice_ids'))->toBeTrue()
        ->and(Schema::hasColumn('checks', 'settlement_request_hash'))->toBeTrue()
        ->and(Schema::hasColumn('checks', 'bounce_request_hash'))->toBeTrue();
});
