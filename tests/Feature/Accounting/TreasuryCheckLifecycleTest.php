<?php

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryAccountType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesService;
use App\Services\Treasury\CheckSettlementService;
use Database\Seeders\AccountSeeder;

function createTreasurySalesContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_default', true)->firstOrFail();
    $fiscalYear = FiscalYear::query()->where('store_id', $store->id)->where('title', '1405')->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CHECK-CUSTOMER',
        'name' => 'مشتری چک',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 10_000_000,
    ]);
    $product = Product::query()->create([
        'code' => 'CHECK-P-001',
        'name' => 'کالای چک',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
    ]);
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 5,
        unitCost: 100,
        movementDate: '2026-05-01',
        idempotencyKey: 'check-opening',
        movementType: InventoryMovementType::Opening,
    );
    $sales = app(SalesService::class);
    $draft = $sales->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-05-02',
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]],
    );
    $invoice = $sales->confirm($draft, $warehouse->id, $manager);

    return compact('store', 'branch', 'warehouse', 'fiscalYear', 'party', 'customer', 'invoice', 'manager');
}

function createCustomerCheckReceipt(array $context, string $number, int $amount): array
{
    $check = Check::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'check_number' => $number,
        'bank_name' => 'بانک تست',
        'amount' => $amount,
        'due_date' => '2026-05-10',
        'type' => CheckType::Receivable,
        'status' => CheckStatus::Pending,
        'customer_id' => $context['customer']->id,
    ]);
    $receipt = app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        customerId: $context['customer']->id,
        receiptDate: '2026-05-03',
        paymentMethod: PaymentMethod::Check,
        allocations: [['invoice_id' => $context['invoice']->id, 'amount' => $amount]],
        approver: $context['manager'],
        idempotencyKey: 'receipt-check-'.$number,
        checkId: $check->id,
    );

    return [$check, $receipt];
}

it('collects a pending customer check into the bank', function () {
    $context = createTreasurySalesContext();
    [$check, $receipt] = createCustomerCheckReceipt($context, 'R-100', 100);
    $bank = TreasuryAccount::query()
        ->where('branch_id', $context['branch']->id)
        ->where('type', TreasuryAccountType::Bank)
        ->firstOrFail();

    $settled = app(CheckSettlementService::class)->collectReceivable(
        $check,
        '2026-05-10',
        $context['manager'],
        $bank->id,
    );
    $entry = JournalEntry::query()->where('source_type', Check::class)->where('source_id', $check->id)->firstOrFail();

    expect($settled->status)->toBe(CheckStatus::Collected)
        ->and($settled->settlement_entry_id)->toBe($entry->id)
        ->and($receipt->fresh()->status->value)->toBe('confirmed')
        ->and($entry->totalDebit())->toBe('100.00')
        ->and($entry->totalCredit())->toBe('100.00');
});

it('bounces a customer check and reopens the invoice receivable', function () {
    $context = createTreasurySalesContext();
    [$check] = createCustomerCheckReceipt($context, 'R-200', 100);
    $bounced = app(CheckSettlementService::class)->bounce(
        $check,
        '2026-05-11',
        $context['manager'],
    );
    $entry = JournalEntry::query()->where('source_type', Check::class)->where('source_id', $check->id)->firstOrFail();

    expect($bounced->status)->toBe(CheckStatus::Bounced)
        ->and(Receipt::query()->firstOrFail()->status->value)->toBe('bounced')
        ->and($context['invoice']->fresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and($entry->totalDebit())->toBe('100.00')
        ->and($entry->totalCredit())->toBe('100.00');
});

it('collects a prior-year check in the next fiscal year', function () {
    $context = createTreasurySalesContext();
    [$check] = createCustomerCheckReceipt($context, 'R-300', 100);
    app(FiscalYearSetupService::class)->setupJalaliYear($context['store'], 1406);
    $bank = TreasuryAccount::query()
        ->where('branch_id', $context['branch']->id)
        ->where('type', TreasuryAccountType::Bank)
        ->firstOrFail();

    $settled = app(CheckSettlementService::class)->collectReceivable(
        $check,
        '2027-04-01',
        $context['manager'],
        $bank->id,
    );

    expect($settled->status)->toBe(CheckStatus::Collected)
        ->and($settled->settlementEntry->fiscalYear->title)->toBe('1406');
});
