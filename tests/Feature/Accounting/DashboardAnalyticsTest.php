<?php

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Check;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Inventory\InventoryService;
use App\Services\Reporting\FinancialStatementReportService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function dashboardAnalyticsContext(): array
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
    $owner = User::factory()->create();
    $owner->forceFill(['role' => UserRole::Manager])->save();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'DASHBOARD-CUSTOMER',
        'name' => 'مشتری داشبورد',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'DASHBOARD-PRODUCT',
        'name' => 'کالای داشبورد',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
        'reorder_level' => 5,
        'is_active' => true,
    ]);
    $date = today()->toDateString();
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 2,
        unitCost: 100,
        movementDate: $date,
        idempotencyKey: 'dashboard-opening-'.$owner->id,
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
            'created_by' => $owner->id,
        ],
        [[
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 10,
        ]],
        'dashboard-sale-'.$owner->id,
    );
    $invoice = $sales->confirm($invoice, $warehouse->id, $owner);
    app(CustomerReceiptService::class)->receive(
        storeId: $store->id,
        branchId: $branch->id,
        fiscalYearId: $fiscalYear->id,
        customerId: $customer->id,
        receiptDate: $date,
        paymentMethod: PaymentMethod::Cash,
        allocations: [['invoice_id' => $invoice->id, 'amount' => '100']],
        approver: $owner,
        idempotencyKey: 'dashboard-receipt-'.$owner->id,
    );
    Check::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'document_number' => 'CK-DASHBOARD-OVERDUE',
        'check_number' => 'DASH-1',
        'bank_name' => 'بانک داشبورد',
        'amount' => '100.00',
        'due_date' => today()->subDay()->toDateString(),
        'type' => 'receivable',
        'customer_id' => $customer->id,
        'created_by' => $owner->id,
    ]);
    Check::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'document_number' => 'CK-DASHBOARD-SOON',
        'check_number' => 'DASH-2',
        'bank_name' => 'بانک داشبورد',
        'amount' => '100.00',
        'due_date' => today()->addDays(3)->toDateString(),
        'type' => 'receivable',
        'customer_id' => $customer->id,
        'created_by' => $owner->id,
    ]);
    Check::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'document_number' => 'CK-DASHBOARD-LATER',
        'check_number' => 'DASH-3',
        'bank_name' => 'بانک داشبورد',
        'amount' => '100.00',
        'due_date' => today()->addDays(30)->toDateString(),
        'type' => 'receivable',
        'customer_id' => $customer->id,
        'created_by' => $owner->id,
    ]);
    $cash = Account::query()
        ->where('store_id', $store->id)
        ->where('code', '1101')
        ->firstOrFail();
    $salesAccount = Account::query()
        ->where('store_id', $store->id)
        ->where('code', '4101')
        ->firstOrFail();
    $oldEntryId = DB::table('journal_entries')->insertGetId([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $fiscalYear->id,
        'accounting_period_id' => $fiscalYear->periods()->firstOrFail()->id,
        'entry_number' => 'OLD-DASHBOARD-'.$owner->id,
        'entry_date' => $fiscalYear->starts_on->subDay()->toDateString(),
        'description' => 'درآمد سال قبل',
        'status' => 'posted',
        'created_by' => $owner->id,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journal_entry_lines')->insert([
        [
            'journal_entry_id' => $oldEntryId,
            'account_id' => $cash->id,
            'debit' => '1000.00',
            'credit' => '0.00',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'journal_entry_id' => $oldEntryId,
            'account_id' => $salesAccount->id,
            'debit' => '0.00',
            'credit' => '1000.00',
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    return compact(
        'store',
        'branch',
        'fiscalYear',
        'owner',
        'customer',
        'invoice',
        'product',
    );
}

it('shows real financial metrics to managers and accountants', function (UserRole $role) {
    $context = dashboardAnalyticsContext();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => $role])->save();
    $expectedProfit = app(FinancialStatementReportService::class)
        ->incomeStatement(
            $context['store']->id,
            $context['fiscalYear']->starts_on->toDateString(),
            today()->toDateString(),
            $context['branch']->id,
        )['net_profit'];

    $this->actingAs($viewer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardHome')
            ->where('dashboard.mode', 'financial')
            ->where('dashboard.metrics.cash_balance', '1100.00')
            ->where('dashboard.metrics.receivables', '340.00')
            ->where('dashboard.metrics.sales_today', $context['invoice']->total)
            ->where('dashboard.metrics.open_sales', 1)
            ->where('dashboard.metrics.overdue_checks', 1)
            ->where('dashboard.metrics.due_soon_checks', 1)
            ->where('dashboard.metrics.pending_checks_amount', '300.00')
            ->where('dashboard.metrics.net_profit', $expectedProfit)
            ->where('dashboard.low_stock.count', 1)
            ->has('dashboard.trend', 7)
            ->has('dashboard.monthly_trend', 6)
            ->where(
                'dashboard.recent_activity',
                fn ($activity) => collect($activity)->contains('kind', 'check'),
            ));
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('limits cashier dashboard data to sales', function () {
    $context = dashboardAnalyticsContext();
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();

    $this->actingAs($cashier)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.mode', 'sales')
            ->where('dashboard.metrics.sales_today', $context['invoice']->total)
            ->missing('dashboard.metrics.cash_balance')
            ->missing('dashboard.metrics.payables')
            ->missing('dashboard.metrics.receivables'));
});

it('shows only inventory signals to warehouse users', function () {
    dashboardAnalyticsContext();
    $warehouseUser = User::factory()->create();
    $warehouseUser->forceFill(['role' => UserRole::Warehouse])->save();

    $this->actingAs($warehouseUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.mode', 'inventory')
            ->where('dashboard.low_stock.count', 1)
            ->where('dashboard.metrics', [])
            ->where('dashboard.recent_activity', []));
});

it('hides analytics from viewer accounts', function () {
    dashboardAnalyticsContext();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => UserRole::Viewer])->save();

    $this->actingAs($viewer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.mode', 'limited')
            ->where('dashboard.metrics', [])
            ->where('dashboard.low_stock.count', 0));
});
