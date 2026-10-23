<?php

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\JalaliDateService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountSeeder;
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

    return compact('store', 'branch', 'fiscalYear', 'owner', 'invoice', 'product');
}

it('shows real financial metrics to managers and accountants', function (UserRole $role) {
    $context = dashboardAnalyticsContext();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => $role])->save();

    $this->actingAs($viewer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardHome')
            ->where('dashboard.mode', 'financial')
            ->where('dashboard.metrics.cash_balance', '100.00')
            ->where('dashboard.metrics.receivables', '340.00')
            ->where('dashboard.metrics.sales_today', $context['invoice']->total)
            ->where('dashboard.metrics.open_sales', 1)
            ->where('dashboard.low_stock.count', 1)
            ->has('dashboard.trend', 7)
            ->has('dashboard.recent_activity'));
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
