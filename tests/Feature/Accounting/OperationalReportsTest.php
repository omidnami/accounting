<?php

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Reporting\AgingReportService;
use App\Services\Reporting\CsvExportService;
use App\Services\Reporting\PartyStatementReportService;
use App\Services\Reporting\SalesOperationsReportService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function operationalReportContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->firstOrFail();
    $date = $fiscalYear->starts_on->toDateString();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'OPS-CUSTOMER',
        'name' => 'مشتری گزارش عملیاتی',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 10_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'OPS-SUPPLIER',
        'name' => 'تأمین‌کننده گزارش عملیاتی',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'OPS-PRODUCT',
        'name' => 'کالای گزارش سود',
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
        idempotencyKey: 'ops-opening',
        movementType: InventoryMovementType::Opening,
    );
    $sales = app(SalesService::class);
    $invoice = $sales->createDraft(
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
            'quantity' => 2,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'ops-sale',
    );
    $invoice = $sales->confirm($invoice, $warehouse->id, $manager);
    $return = app(SalesReturnService::class)->createDraft(
        $invoice,
        [[
            'invoice_item_id' => $invoice->items()->firstOrFail()->id,
            'quantity' => 1,
        ]],
        $date,
        $manager->id,
        'ops-return',
    );
    app(SalesReturnService::class)->confirm($return, $warehouse->id, $manager);
    $purchase = app(PurchaseService::class)->createDraft(
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
            'unit_price' => 120,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'ops-purchase',
    );
    app(PurchaseService::class)->confirm($purchase, $warehouse->id, $manager);

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'manager',
        'customerParty',
        'supplierParty',
        'product',
        'invoice',
    ) + ['date' => $date];
}

it('reports net sales, cogs and product gross profit from snapshots', function () {
    $context = operationalReportContext();
    $report = app(SalesOperationsReportService::class)->report(
        $context['store']->id,
        $context['fiscalYear']->starts_on->toDateString(),
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );

    expect($report['summary']['revenue'])->toBe('200.00')
        ->and($report['summary']['cogs'])->toBe('100.00')
        ->and($report['summary']['gross_profit'])->toBe('100.00')
        ->and($report['summary']['gross_margin'])->toBe('50.00')
        ->and($report['summary']['sales_invoices'])->toBe(1)
        ->and($report['summary']['return_invoices'])->toBe(1)
        ->and($report['products'][0]['product_id'])->toBe($context['product']->id);
});

it('builds party statements and aging from outstanding invoice balances', function () {
    $context = operationalReportContext();
    $statement = app(PartyStatementReportService::class)->report(
        $context['customerParty'],
        $context['store']->id,
        $context['fiscalYear']->starts_on->toDateString(),
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );
    $receivables = app(AgingReportService::class)->receivables(
        $context['store']->id,
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );
    $payables = app(AgingReportService::class)->payables(
        $context['store']->id,
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );

    expect($statement['closing_balance'])->toBe('200.00')
        ->and($statement['rows'])->not->toBeEmpty()
        ->and($receivables['total'])->toBe('200.00')
        ->and($payables['total'])->toBe('600.00')
        ->and($receivables['rows'][0]['party']['id'])->toBe($context['customerParty']->id)
        ->and($payables['rows'][0]['party']['id'])->toBe($context['supplierParty']->id)
        ->and($receivables['rows'][0]['bucket'])->toBe('over_90')
        ->and($payables['rows'][0]['bucket'])->toBe('over_90');
});

it('renders operational reports and account ledger for financial roles', function () {
    $context = operationalReportContext();

    $this->actingAs($context['manager'])
        ->get(route('reports.operations', [
            'from' => $context['fiscalYear']->starts_on->toDateString(),
            'to' => $context['fiscalYear']->ends_on->toDateString(),
            'party_id' => $context['customerParty']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Operations')
            ->where('statement.party.id', $context['customerParty']->id)
            ->where('statement.rows.current_page', 1)
            ->where('receivablesAging.rows.current_page', 1)
            ->where('receivablesAging.total', '200.00')
            ->where('payablesAging.total', '600.00')
            ->where('sales.daily.current_page', 1)
            ->where('sales.products.current_page', 1)
            ->where('sales.summary.gross_profit', '100.00'));

    $this->actingAs($context['manager'])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->has('accounts')
            ->has('accountLedger.rows')
            ->where('filters.account_id', fn ($value) => is_int($value)));
});

it('marks profit as incomplete when a sales cost snapshot is missing', function () {
    $context = operationalReportContext();
    DB::table('invoice_items')
        ->where('invoice_id', $context['invoice']->id)
        ->update(['total_cost_snapshot' => null]);
    $report = app(SalesOperationsReportService::class)->report(
        $context['store']->id,
        $context['fiscalYear']->starts_on->toDateString(),
        $context['fiscalYear']->ends_on->toDateString(),
        $context['branch']->id,
    );

    expect($report['summary']['cogs_complete'])->toBeFalse()
        ->and($report['summary']['incomplete_cogs_count'])->toBe(1)
        ->and($report['daily'][0]['cogs_complete'])->toBeFalse()
        ->and($report['products'][0]['cogs_complete'])->toBeFalse();

    $response = $this->actingAs($context['manager'])
        ->get(route('reports.export.operations', [
            'report' => 'sales_daily',
            'from' => $context['fiscalYear']->starts_on->toDateString(),
            'to' => $context['fiscalYear']->ends_on->toDateString(),
        ]));
    $content = ltrim($response->streamedContent(), "\xEF\xBB\xBF");
    $rows = collect(explode("\n", trim($content)))
        ->map(fn (string $line): array => str_getcsv($line, ';', '"', ''));
    $daily = $rows->firstWhere(
        fn (array $row): bool => $row[0] === $context['fiscalYear']->starts_on->toDateString(),
    );

    expect($daily)->not->toBeNull()
        ->and($daily[4])->toBe('')
        ->and($daily[5])->toBe('خیر');
});

it('keeps inactive accounts selectable for historical reports', function () {
    $context = operationalReportContext();
    $cash = Account::query()
        ->where('store_id', $context['store']->id)
        ->where('code', '1101')
        ->firstOrFail();
    $cash->update(['is_active' => false]);
    $this->actingAs($context['manager'])
        ->get(route('reports.index', [
            'account_id' => $cash->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.account_id', $cash->id)
            ->missing('filters.branch_id'));
});

it('validates effective report ranges when only one date side is provided', function () {
    $context = operationalReportContext();

    $this->actingAs($context['manager'])
        ->get(route('reports.operations', ['from' => '2030-01-01']))
        ->assertSessionHasErrors('to');

    $this->actingAs($context['manager'])
        ->get(route('reports.index', ['to' => '2000-01-01']))
        ->assertSessionHasErrors('to');
});

it('exports every financial and operational report as CSV', function () {
    $context = operationalReportContext();
    $cash = Account::query()
        ->where('store_id', $context['store']->id)
        ->where('code', '1101')
        ->firstOrFail();
    $range = [
        'from' => $context['fiscalYear']->starts_on->toDateString(),
        'to' => $context['fiscalYear']->ends_on->toDateString(),
    ];
    $financialReports = [
        'trial' => ['header' => 'کد حساب', 'summary' => 'جمع کل'],
        'income' => ['header' => 'مبلغ', 'summary' => 'سود خالص دوره'],
        'balance' => ['header' => 'مبلغ', 'summary' => 'جمع حقوق صاحبان سهام'],
        'journal' => ['header' => 'شماره سند', 'summary' => 'جمع کل'],
        'ledger' => ['header' => 'مانده', 'summary' => 'مانده اول دوره'],
    ];

    foreach ($financialReports as $report => $expected) {
        $response = $this->actingAs($context['manager'])->get(route('reports.export.financial', [
            ...$range,
            'report' => $report,
            'account_id' => $cash->id,
        ]));
        $content = $response->streamedContent();

        expect($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
            ->and($content)->toStartWith("\xEF\xBB\xBF")
            ->and($content)->toContain($expected['header'])
            ->and($content)->toContain($expected['summary']);
    }

    $operationalReports = [
        'statement' => ['header' => 'شماره سند', 'summary' => 'مانده اول دوره'],
        'aging_receivable' => ['header' => 'روز تأخیر', 'summary' => 'جمع کل'],
        'aging_payable' => ['header' => 'روز تأخیر', 'summary' => 'جمع کل'],
        'sales_daily' => ['header' => 'ثبت کامل بهای تمام‌شده', 'summary' => 'جمع کل'],
        'sales_products' => ['header' => 'حاشیه سود', 'summary' => 'جمع کل'],
    ];

    foreach ($operationalReports as $report => $expected) {
        $response = $this->actingAs($context['manager'])->get(route('reports.export.operations', [
            ...$range,
            'report' => $report,
            'party_id' => $context['customerParty']->id,
        ]));
        $content = $response->streamedContent();

        expect($response->getStatusCode())->toBe(200)
            ->and($content)->toStartWith("\xEF\xBB\xBF")
            ->and($content)->toContain($expected['header'])
            ->and($content)->toContain($expected['summary']);
    }
});

it('exports cardex without financial columns for warehouse users', function () {
    $context = operationalReportContext();
    $range = [
        'product_id' => $context['product']->id,
        'warehouse_id' => $context['warehouse']->id,
        'from' => $context['fiscalYear']->starts_on->toDateString(),
        'to' => $context['fiscalYear']->ends_on->toDateString(),
    ];

    $managerCardex = $this->actingAs($context['manager'])
        ->get(route('inventory.cardex.export', $range));
    $managerCardex->assertOk();
    expect($managerCardex->streamedContent())->toContain('بهای واحد');

    $warehouseUser = User::factory()->create();
    $warehouseUser->forceFill(['role' => UserRole::Warehouse])->save();
    $warehouseCardex = $this->actingAs($warehouseUser)
        ->get(route('inventory.cardex.export', $range));
    $warehouseCardex->assertOk();
    $content = $warehouseCardex->streamedContent();

    expect($content)->not->toContain('بهای واحد')
        ->and($content)->not->toContain('بهای کل')
        ->and($content)->not->toContain('ارزش پس از گردش');
});

it('preserves cardex movement and direction filters in CSV exports', function () {
    $context = operationalReportContext();
    $range = [
        'product_id' => $context['product']->id,
        'warehouse_id' => $context['warehouse']->id,
        'from' => $context['fiscalYear']->starts_on->toDateString(),
        'to' => $context['fiscalYear']->ends_on->toDateString(),
    ];

    $saleExport = $this->actingAs($context['manager'])
        ->get(route('inventory.cardex.export', [
            ...$range,
            'movement_type' => 'sale',
            'direction' => 'out',
        ]));
    $saleContent = $saleExport->streamedContent();

    $purchaseExport = $this->actingAs($context['manager'])
        ->get(route('inventory.cardex.export', [
            ...$range,
            'movement_type' => 'purchase',
            'direction' => 'in',
        ]));
    $purchaseContent = $purchaseExport->streamedContent();

    expect($saleExport->getStatusCode())->toBe(200)
        ->and($saleContent)->toContain(';sale;')
        ->and($saleContent)->not->toContain(';purchase;')
        ->and($purchaseContent)->toContain(';purchase;')
        ->and($purchaseContent)->not->toContain(';sale;');

    $this->actingAs($context['manager'])
        ->get(route('inventory.cardex.export', [
            ...$range,
            'movement_type' => 'sale',
            'direction' => 'in',
        ]))
        ->assertSessionHasErrors('movement_type');
});

it('protects report exports by role', function () {
    $context = operationalReportContext();
    $financeRange = [
        'report' => 'trial',
        'from' => $context['fiscalYear']->starts_on->toDateString(),
        'to' => $context['fiscalYear']->ends_on->toDateString(),
    ];

    foreach ([UserRole::Cashier, UserRole::Warehouse] as $role) {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        $this->actingAs($user)
            ->get(route('reports.export.financial', $financeRange))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('reports.export.operations', [
                ...$financeRange,
                'report' => 'sales_daily',
            ]))
            ->assertForbidden();
    }

    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();
    $this->actingAs($cashier)
        ->get(route('inventory.cardex.export', [
            'product_id' => $context['product']->id,
            'warehouse_id' => $context['warehouse']->id,
            'from' => $context['fiscalYear']->starts_on->toDateString(),
            'to' => $context['fiscalYear']->ends_on->toDateString(),
        ]))
        ->assertForbidden();
});

it('neutralizes spreadsheet formulas in CSV text cells', function () {
    $response = app(CsvExportService::class)->download("unsafe\r\n/path.csv", ['value'], [
        ['=1+1'],
        ['  -2+3'],
        ['+CMD'],
        ['@SUM(A1:A2)'],
        ["\t=1+1"],
        ['-12.50'],
        ['متن عادی'],
    ]);
    $content = (new TestResponse($response))->streamedContent();
    $disposition = (string) $response->headers->get('content-disposition');

    expect($content)->toContain("'=1+1")
        ->and($content)->toContain("'  -2+3")
        ->and($content)->toContain("'+CMD")
        ->and($content)->toContain("'@SUM(A1:A2)")
        ->and($content)->not->toContain("'-12.50")
        ->and($content)->toContain('-12.50')
        ->and($content)->toContain('متن عادی')
        ->and($disposition)->toContain('unsafe---path.csv')
        ->and($disposition)->not->toContain("\r")
        ->and($disposition)->not->toContain("\n")
        ->and($disposition)->not->toContain('/');
});
