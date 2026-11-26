<?php

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\AccountingPeriod;
use App\Models\Check;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PrintStation;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\ReturnRefund;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Services\Printing\PrintJobService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Reporting\AgingReportService;
use App\Services\Reporting\ReportExportService;
use App\Services\Returns\ReturnRefundService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use App\Services\Treasury\CheckRegistrationService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array<string, mixed>
 */
function partySnapshotContext(): array
{
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $date = $fiscalYear->starts_on->toDateString();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'SNAPSHOT-CUSTOMER',
        'entity_type' => 'organization',
        'name' => 'مشتری زمان فاکتور',
        'phone' => '02100000001',
        'email' => 'customer@example.test',
        'national_id' => '1000000001',
        'tax_id' => '2000000001',
        'address' => 'آستان مشتری',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 10_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'SNAPSHOT-SUPPLIER',
        'entity_type' => 'organization',
        'name' => 'تأمین‌کننده زمان فاکتور',
        'phone' => '02100000002',
        'email' => 'supplier@example.test',
        'national_id' => '1000000002',
        'tax_id' => '2000000002',
        'address' => 'آستان تأمین‌کننده',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'SNAPSHOT-PRODUCT',
        'name' => 'کالای snapshot',
        'unit' => 'عدد',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 0,
        'is_active' => true,
    ]);

    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 20,
        unitCost: 100,
        movementDate: $date,
        idempotencyKey: 'snapshot-opening',
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
            'due_date' => $date,
            'created_by' => $manager->id,
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'snapshot-sale',
    );
    $salesInvoice = $sales->confirm($salesInvoice, $warehouse->id, $manager);

    $purchases = app(PurchaseService::class);
    $purchaseInvoice = $purchases->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'supplier_id' => $supplier->id,
            'invoice_date' => $date,
            'due_date' => $date,
            'created_by' => $manager->id,
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'snapshot-purchase',
    );
    $purchaseInvoice = $purchases->confirm($purchaseInvoice, $warehouse->id, $manager);

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'date',
        'manager',
        'customerParty',
        'customer',
        'supplierParty',
        'supplier',
        'product',
        'salesInvoice',
        'purchaseInvoice',
    );
}

/**
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
function partySnapshotFinalizedDocuments(array $context): array
{
    $receipt = app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        customerId: $context['customer']->id,
        receiptDate: $context['date'],
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $context['salesInvoice']->id, 'amount' => '100']],
        approver: $context['manager'],
        idempotencyKey: (string) Str::uuid(),
    );
    $payment = app(SupplierPaymentService::class)->pay(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        supplierId: $context['supplier']->id,
        paymentDate: $context['date'],
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $context['purchaseInvoice']->id, 'amount' => '100']],
        approver: $context['manager'],
        idempotencyKey: (string) Str::uuid(),
    );
    $check = app(CheckRegistrationService::class)->register(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        type: CheckType::Receivable,
        partyProfileId: $context['customer']->id,
        checkNumber: 'SNAPSHOT-CHECK',
        bankName: 'بانک snapshot',
        branchName: null,
        amount: '50',
        issueDate: $context['date'],
        dueDate: $context['date'],
        creator: $context['manager'],
        idempotencyKey: (string) Str::uuid(),
    );

    $salesReturn = app(SalesReturnService::class)->createDraft(
        $context['salesInvoice'],
        [['invoice_item_id' => $context['salesInvoice']->items()->firstOrFail()->id, 'quantity' => 1]],
        $context['date'],
        $context['manager']->id,
        null,
        (string) Str::uuid(),
    );
    $purchaseReturn = app(PurchaseReturnService::class)->createDraft(
        $context['purchaseInvoice'],
        [['invoice_item_id' => $context['purchaseInvoice']->items()->firstOrFail()->id, 'quantity' => 1]],
        $context['date'],
        $context['manager']->id,
        null,
        (string) Str::uuid(),
    );

    DB::table('invoices')->whereIn('id', [$salesReturn->id, $purchaseReturn->id])
        ->update(['party_snapshot' => null]);
    $context['customerParty']->update(['name' => 'مشتری زمان تأیید مرجوعی', 'version' => 2]);
    $context['supplierParty']->update(['name' => 'تأمین‌کننده زمان تأیید مرجوعی', 'version' => 2]);

    $salesReturn = app(SalesReturnService::class)->confirm($salesReturn, $context['warehouse']->id, $context['manager']);
    $purchaseReturn = app(PurchaseReturnService::class)->confirm($purchaseReturn, $context['warehouse']->id, $context['manager']);

    $context['customerParty']->update(['name' => 'مشتری زمان refund', 'version' => 3]);
    $refund = app(ReturnRefundService::class)->refund(
        $salesReturn,
        TreasuryAccount::query()->where('branch_id', $context['branch']->id)->where('type', 'cash')->firstOrFail()->id,
        $context['date'],
        '100',
        $context['manager'],
        (string) Str::uuid(),
    );

    return compact('receipt', 'payment', 'check', 'salesReturn', 'purchaseReturn', 'refund') + $context;
}

/**
 * @param  array<string, mixed>  $context
 * @return array<int, string>
 */
function partySnapshotCurrentNames(array $context): array
{
    $context['customerParty']->update(['name' => 'مشتری نام جدید', 'phone' => '02199999991', 'version' => 9]);
    $context['supplierParty']->update(['name' => 'تأمین‌کننده نام جدید', 'phone' => '02199999992', 'version' => 9]);

    return ['مشتری نام جدید', 'تأمین‌کننده نام جدید'];
}

/**
 * @return array<int, array<int, string>>
 */
function partySnapshotCsvRows(Response $response): array
{
    $content = (new TestResponse($response))->streamedContent();

    return collect(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))))
        ->map(fn (string $line): array => str_getcsv($line, ';', '"', ''))
        ->values()
        ->all();
}

it('captures and preserves party identity on all six final document types', function () {
    $context = partySnapshotFinalizedDocuments(partySnapshotContext());
    $salesLine = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($query) => $query
            ->where('source_type', Invoice::class)
            ->where('source_id', $context['salesInvoice']->id))
        ->whereNotNull('party_id')
        ->firstOrFail();
    $receiptLine = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($query) => $query
            ->where('source_type', Receipt::class)
            ->where('source_id', $context['receipt']->id))
        ->whereNotNull('party_id')
        ->firstOrFail();
    $paymentLine = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($query) => $query
            ->where('source_type', Payment::class)
            ->where('source_id', $context['payment']->id))
        ->whereNotNull('party_id')
        ->firstOrFail();
    $returnLine = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($query) => $query
            ->where('source_type', Invoice::class)
            ->where('source_id', $context['salesReturn']->id))
        ->whereNotNull('party_id')
        ->firstOrFail();
    $refundLine = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($query) => $query
            ->where('source_type', ReturnRefund::class)
            ->where('source_id', $context['refund']->id))
        ->whereNotNull('party_id')
        ->firstOrFail();

    expect($context['salesInvoice']->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($context['purchaseInvoice']->party_snapshot['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and($context['receipt']->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($context['payment']->party_snapshot['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and($context['check']->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($context['salesReturn']->party_snapshot['name'])->toBe('مشتری زمان تأیید مرجوعی')
        ->and($context['purchaseReturn']->party_snapshot['name'])->toBe('تأمین‌کننده زمان تأیید مرجوعی')
        ->and($context['refund']->party_snapshot['name'])->toBe('مشتری زمان refund')
        ->and($salesLine->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($receiptLine->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($paymentLine->party_snapshot['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and($returnLine->party_snapshot['name'])->toBe('مشتری زمان تأیید مرجوعی')
        ->and($refundLine->party_snapshot['name'])->toBe('مشتری زمان refund');

    [$currentCustomer, $currentSupplier] = partySnapshotCurrentNames($context);

    expect($context['salesInvoice']->refresh()->partyIdentity()['name'])->toBe('مشتری زمان فاکتور')
        ->and($context['purchaseInvoice']->refresh()->partyIdentity()['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and($context['receipt']->refresh()->partyIdentity()['name'])->toBe('مشتری زمان فاکتور')
        ->and($context['payment']->refresh()->partyIdentity()['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and($context['check']->refresh()->partyIdentity()['name'])->toBe('مشتری زمان فاکتور')
        ->and($context['salesReturn']->refresh()->partyIdentity()['name'])->toBe('مشتری زمان تأیید مرجوعی')
        ->and($context['purchaseReturn']->refresh()->partyIdentity()['name'])->toBe('تأمین‌کننده زمان تأیید مرجوعی')
        ->and($context['refund']->refresh()->partyIdentity()['name'])->toBe('مشتری زمان refund')
        ->and($salesLine->refresh()->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($receiptLine->refresh()->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($paymentLine->refresh()->party_snapshot['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and($returnLine->refresh()->party_snapshot['name'])->toBe('مشتری زمان تأیید مرجوعی')
        ->and($refundLine->refresh()->party_snapshot['name'])->toBe('مشتری زمان refund')
        ->and($context['customerParty']->refresh()->name)->toBe($currentCustomer)
        ->and($context['supplierParty']->refresh()->name)->toBe($currentSupplier);
});

it('uses historical snapshots in treasury and return UI, print payload, and aging export', function () {
    $context = partySnapshotFinalizedDocuments(partySnapshotContext());
    partySnapshotCurrentNames($context);
    $manager = $context['manager'];
    $station = PrintStation::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'name' => 'Snapshot Station',
        'platform' => 'linux',
        'token_hash' => hash('sha256', 'snapshot-station-token'),
        'status' => 'active',
        'last_seen_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('receipts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipts.data.0.customer.name', 'مشتری زمان فاکتور')
            ->where('receipts.data.0.customer.legacy_current', false));
    $this->actingAs($manager)
        ->get(route('receipts.show', $context['receipt']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.customer.name', 'مشتری زمان فاکتور')
            ->where('receipt.customer.phone', '02100000001'));
    $this->actingAs($manager)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payments.data.0.supplier.name', 'تأمین‌کننده زمان فاکتور'));
    $this->actingAs($manager)
        ->get(route('payments.show', $context['payment']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payment.supplier.name', 'تأمین‌کننده زمان فاکتور')
            ->where('payment.supplier.phone', '02100000002'));
    $this->actingAs($manager)
        ->get(route('checks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('checks.data.0.party.name', 'مشتری زمان فاکتور'));
    $this->actingAs($manager)
        ->get(route('checks.show', $context['check']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('check.party.name', 'مشتری زمان فاکتور'));
    $this->actingAs($manager)
        ->get(route('sales.returns.show', $context['salesReturn']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('return_invoice.party.name', 'مشتری زمان تأیید مرجوعی'));
    $this->actingAs($manager)
        ->get(route('purchases.returns.show', $context['purchaseReturn']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('return_invoice.party.name', 'تأمین‌کننده زمان تأیید مرجوعی'));
    $this->actingAs($manager)
        ->get(route('returns.refund.create', $context['salesReturn']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.party.name', 'مشتری زمان تأیید مرجوعی'));

    $printJob = app(PrintJobService::class)->queueSalesInvoice($context['salesInvoice']->refresh());
    $receivableAging = app(AgingReportService::class)->receivables(
        $context['store']->id,
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );
    $payableAging = app(AgingReportService::class)->payables(
        $context['store']->id,
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );
    $receivableExport = app(ReportExportService::class)->operations(
        $context['store'],
        'aging_receivable',
        $context['date'],
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
        null,
    );
    $payableExport = app(ReportExportService::class)->operations(
        $context['store'],
        'aging_payable',
        $context['date'],
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
        null,
    );

    expect($printJob->print_station_id)->toBe($station->id)
        ->and($printJob->payload['customer']['name'])->toBe('مشتری زمان فاکتور')
        ->and($printJob->payload['customer']['phone'])->toBe('02100000001')
        ->and($printJob->payload['customer']['code'])->toBe('SNAPSHOT-CUSTOMER')
        ->and($printJob->payload['customer']['legacy_current'])->toBeFalse()
        ->and($receivableAging['rows'][0]['party']['name'])->toBe('مشتری زمان فاکتور')
        ->and($payableAging['rows'][0]['party']['name'])->toBe('تأمین‌کننده زمان فاکتور')
        ->and(collect(partySnapshotCsvRows($receivableExport))->flatten())->toContain('مشتری زمان فاکتور')
        ->and(collect(partySnapshotCsvRows($receivableExport))->flatten())->not->toContain('مشتری نام جدید')
        ->and(collect(partySnapshotCsvRows($payableExport))->flatten())->toContain('تأمین‌کننده زمان فاکتور')
        ->and(collect(partySnapshotCsvRows($payableExport))->flatten())->not->toContain('تأمین‌کننده نام جدید');
});

it('marks legacy null snapshots explicitly and falls back to the current party identity', function () {
    $context = partySnapshotFinalizedDocuments(partySnapshotContext());
    DB::table('invoices')->whereIn('id', [
        $context['salesInvoice']->id,
        $context['purchaseInvoice']->id,
        $context['salesReturn']->id,
        $context['purchaseReturn']->id,
    ])->update(['party_snapshot' => null]);
    DB::table('receipts')->where('id', $context['receipt']->id)->update(['party_snapshot' => null]);
    DB::table('payments')->where('id', $context['payment']->id)->update(['party_snapshot' => null]);
    DB::table('checks')->where('id', $context['check']->id)->update(['party_snapshot' => null]);
    DB::table('return_refunds')->where('id', $context['refund']->id)->update(['party_snapshot' => null]);
    DB::table('journal_entry_lines')->whereNotNull('party_id')->update(['party_snapshot' => null]);
    partySnapshotCurrentNames($context);

    expect($context['salesInvoice']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['salesInvoice']->partyIdentity()['name'])->toBe('مشتری نام جدید')
        ->and($context['purchaseInvoice']->refresh()->partyIdentity()['name'])->toBe('تأمین‌کننده نام جدید')
        ->and($context['receipt']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['payment']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['check']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['salesReturn']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['purchaseReturn']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['refund']->refresh()->partyIdentity()['legacy_current'])->toBeTrue()
        ->and($context['refund']->partyIdentity()['name'])->toBe('مشتری نام جدید')
        ->and(JournalEntryLine::query()->whereNotNull('party_id')->whereNotNull('party_snapshot')->exists())->toBeFalse();

    $this->actingAs($context['manager'])
        ->get(route('receipts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipts.data.0.customer.name', 'مشتری نام جدید')
            ->where('receipts.data.0.customer.legacy_current', true));
    $this->actingAs($context['manager'])
        ->get(route('payments.show', $context['payment']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payment.supplier.name', 'تأمین‌کننده نام جدید')
            ->where('payment.supplier.legacy_current', true));
    $this->actingAs($context['manager'])
        ->get(route('checks.show', $context['check']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('check.party.name', 'مشتری نام جدید')
            ->where('check.party.legacy_current', true));
    $this->actingAs($context['manager'])
        ->get(route('sales.returns.show', $context['salesReturn']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('return_invoice.party.name', 'مشتری نام جدید')
            ->where('return_invoice.party.legacy_current', true));
    $this->actingAs($context['manager'])
        ->get(route('returns.refund.create', $context['salesReturn']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.party.name', 'مشتری نام جدید')
            ->where('invoice.party.legacy_current', true));

    $aging = app(AgingReportService::class)->receivables(
        $context['store']->id,
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );
    $export = app(ReportExportService::class)->operations(
        $context['store'],
        'aging_receivable',
        $context['date'],
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
        null,
    );

    expect($aging['rows'][0]['party']['name'])->toBe('مشتری نام جدید')
        ->and($aging['rows'][0]['party']['legacy_current'])->toBeTrue()
        ->and(collect(partySnapshotCsvRows($export))->flatten())->toContain('مشتری نام جدید');
});

it('uses current identity explicitly when printing a legacy invoice without a snapshot', function () {
    $context = partySnapshotContext();
    DB::table('invoices')->where('id', $context['salesInvoice']->id)->update(['party_snapshot' => null]);
    partySnapshotCurrentNames($context);
    PrintStation::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'name' => 'Legacy Snapshot Station',
        'platform' => 'linux',
        'token_hash' => hash('sha256', 'legacy-snapshot-station-token'),
        'status' => 'active',
        'last_seen_at' => now(),
    ]);

    $job = app(PrintJobService::class)->queueSalesInvoice($context['salesInvoice']->refresh());

    expect($job->payload['customer']['name'])->toBe('مشتری نام جدید')
        ->and($job->payload['customer']['phone'])->toBe('02199999991')
        ->and($job->payload['customer']['legacy_current'])->toBeTrue();
});

it('backfills legacy pending checks when they are linked to new treasury documents', function () {
    $context = partySnapshotContext();
    $receivableCheck = Check::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'document_number' => 'LEGACY-RECEIVABLE-CHECK',
        'check_number' => 'LEGACY-RECEIVABLE-CHECK',
        'bank_name' => 'بانک قدیمی',
        'amount' => '50',
        'due_date' => $context['date'],
        'type' => CheckType::Receivable,
        'customer_id' => $context['customer']->id,
        'created_by' => $context['manager']->id,
    ]);
    $payableCheck = Check::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'document_number' => 'LEGACY-PAYABLE-CHECK',
        'check_number' => 'LEGACY-PAYABLE-CHECK',
        'bank_name' => 'بانک قدیمی',
        'amount' => '50',
        'due_date' => $context['date'],
        'type' => CheckType::Payable,
        'supplier_id' => $context['supplier']->id,
        'created_by' => $context['manager']->id,
    ]);

    app(CustomerReceiptService::class)->receive(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        customerId: $context['customer']->id,
        receiptDate: $context['date'],
        paymentMethod: PaymentMethod::Check,
        allocations: [['invoice_id' => $context['salesInvoice']->id, 'amount' => '50']],
        approver: $context['manager'],
        idempotencyKey: (string) Str::uuid(),
        checkId: $receivableCheck->id,
    );
    app(SupplierPaymentService::class)->pay(
        storeId: $context['store']->id,
        branchId: $context['branch']->id,
        fiscalYearId: $context['fiscalYear']->id,
        supplierId: $context['supplier']->id,
        paymentDate: $context['date'],
        paymentMethod: PaymentMethod::Check,
        allocations: [['invoice_id' => $context['purchaseInvoice']->id, 'amount' => '50']],
        approver: $context['manager'],
        idempotencyKey: (string) Str::uuid(),
        checkId: $payableCheck->id,
    );

    expect($receivableCheck->refresh()->party_snapshot['name'])->toBe('مشتری زمان فاکتور')
        ->and($payableCheck->refresh()->party_snapshot['name'])->toBe('تأمین‌کننده زمان فاکتور');
});

it('rejects finalizing every document type without a party identity snapshot', function () {
    $context = partySnapshotContext();
    $settings = app(SettingService::class);
    $period = AccountingPeriod::query()
        ->where('fiscal_year_id', $context['fiscalYear']->id)
        ->whereDate('starts_on', '<=', $context['date'])
        ->whereDate('ends_on', '>=', $context['date'])
        ->firstOrFail();

    $draftInvoice = app(SalesService::class)->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $context['date'],
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'snapshot-rejected-invoice',
    );
    $posting = app(PostingService::class);
    $posting->post(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'accounting_period_id' => $period->id,
            'entry_date' => $context['date'],
            'description' => 'سند آزمون guard فاکتور',
            'source_type' => Invoice::class,
            'source_id' => $draftInvoice->id,
            'created_by' => $context['manager']->id,
        ],
        [
            [
                'account_id' => $settings->defaultAccountId('accounts_receivable', $context['store']->id),
                'party_id' => $context['customerParty']->id,
                'debit' => '200',
                'credit' => '0',
            ],
            [
                'account_id' => $settings->defaultAccountId('sales', $context['store']->id),
                'debit' => '0',
                'credit' => '200',
            ],
        ],
        $context['manager'],
    );
    DB::table('invoices')->where('id', $draftInvoice->id)->update(['party_snapshot' => null]);

    expect(fn () => $draftInvoice->refresh()->confirmDocument())
        ->toThrow(DomainException::class, 'هویت طرف‌حساب فاکتور باید snapshot شود');

    $draftEntry = $posting->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'accounting_period_id' => $period->id,
            'entry_date' => $context['date'],
            'description' => 'سند آزمون guard ردیف',
            'created_by' => $context['manager']->id,
        ],
        [
            [
                'account_id' => $settings->defaultAccountId('accounts_receivable', $context['store']->id),
                'party_id' => $context['customerParty']->id,
                'debit' => '100',
                'credit' => '0',
            ],
            [
                'account_id' => $settings->defaultAccountId('sales', $context['store']->id),
                'debit' => '0',
                'credit' => '100',
            ],
        ],
    );
    DB::table('journal_entry_lines')->where('journal_entry_id', $draftEntry->id)
        ->whereNotNull('party_id')
        ->update(['party_snapshot' => null]);

    expect(fn () => $draftEntry->refresh()->finalizePosting($context['manager']))
        ->toThrow(DomainException::class, 'همهٔ ردیف‌های سند باید snapshot شود');

    $receipt = Receipt::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'receipt_number' => 'SNAPSHOT-REJECT-RECEIPT',
        'receipt_date' => $context['date'],
        'amount' => '10',
        'payment_method' => PaymentMethod::Cash,
        'customer_id' => $context['customer']->id,
        'created_by' => $context['manager']->id,
    ]);
    $payment = Payment::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'payment_number' => 'SNAPSHOT-REJECT-PAYMENT',
        'payment_date' => $context['date'],
        'amount' => '10',
        'payment_method' => PaymentMethod::Cash,
        'supplier_id' => $context['supplier']->id,
        'created_by' => $context['manager']->id,
    ]);
    $check = Check::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'document_number' => 'SNAPSHOT-REJECT-CHECK',
        'check_number' => 'SNAPSHOT-REJECT-CHECK',
        'bank_name' => 'بانک آزمون',
        'amount' => '10',
        'due_date' => $context['date'],
        'type' => CheckType::Receivable,
        'customer_id' => $context['customer']->id,
        'created_by' => $context['manager']->id,
    ]);
    $return = app(SalesReturnService::class)->createDraft(
        $context['salesInvoice'],
        [['invoice_item_id' => $context['salesInvoice']->items()->firstOrFail()->id, 'quantity' => 1]],
        $context['date'],
        $context['manager']->id,
        null,
        (string) Str::uuid(),
    );
    $refund = ReturnRefund::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'source_invoice_id' => $return->id,
        'original_invoice_id' => $context['salesInvoice']->id,
        'party_id' => $context['customerParty']->id,
        'direction' => 'customer',
        'amount' => '10',
        'refund_date' => $context['date'],
        'treasury_account_id' => TreasuryAccount::query()->where('branch_id', $context['branch']->id)->where('type', 'cash')->firstOrFail()->id,
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
        'request_hash' => hash('sha256', 'snapshot-rejected-refund'),
        'created_by' => $context['manager']->id,
    ]);

    expect(fn () => $receipt->markConfirmed())
        ->toThrow(DomainException::class, 'هویت طرف‌حساب دریافت باید snapshot شود')
        ->and(fn () => $payment->markConfirmed())
        ->toThrow(DomainException::class, 'هویت طرف‌حساب پرداخت باید snapshot شود')
        ->and(fn () => $check->markSettled(new JournalEntry, new TreasuryAccount))
        ->toThrow(DomainException::class)
        ->and(fn () => $refund->forceFill(['status' => 'posted'])->save())
        ->toThrow(DomainException::class)
        ->and($receipt->refresh()->status->value)->toBe('pending')
        ->and($payment->refresh()->status->value)->toBe('pending')
        ->and($check->refresh()->status)->toBe(CheckStatus::Pending)
        ->and($refund->refresh()->status)->toBe('pending')
        ->and($draftEntry->refresh()->status->value)->toBe('draft')
        ->and($draftInvoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});
