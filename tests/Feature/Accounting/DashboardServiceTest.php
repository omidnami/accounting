<?php

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Dashboard\DashboardService;
use App\Support\BusinessClock;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function dashboardServiceContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $date = app(BusinessClock::class)->today($store)->toDateString();
    $period = $fiscalYear->periods()
        ->where('starts_on', '<=', $date)
        ->where('ends_on', '>=', $date)
        ->firstOrFail();
    $cash = Account::query()->where('store_id', $store->id)->where('code', '1101')->firstOrFail();
    $sales = Account::query()->where('store_id', $store->id)->where('code', '4101')->firstOrFail();
    $product = Product::query()->create([
        'code' => 'DASHBOARD-SERVICE-PRODUCT',
        'name' => 'کالای کم‌موجود داشبورد',
        'purchase_price' => 100,
        'sale_price' => 200,
        'reorder_level' => 5,
        'reorder_quantity' => 10,
        'is_active' => true,
    ]);

    InventoryBalance::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'average_cost' => 100,
        'inventory_value' => 200,
    ]);

    $otherBranch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'DASHBOARD-OTHER',
        'name' => 'شعبه دیگر داشبورد',
        'is_active' => true,
    ]);
    $otherWarehouse = Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $otherBranch->id,
        'code' => 'MAIN',
        'name' => 'انبار شعبه دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);
    InventoryBalance::query()->create([
        'store_id' => $store->id,
        'branch_id' => $otherBranch->id,
        'warehouse_id' => $otherWarehouse->id,
        'product_id' => $product->id,
        'quantity' => 100,
        'average_cost' => 100,
        'inventory_value' => 10000,
    ]);

    $invoiceAttributes = [
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $fiscalYear->id,
        'invoice_date' => $date,
        'type' => InvoiceType::Sales->value,
        'status' => InvoiceStatus::Confirmed->value,
        'subtotal' => 120,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 120,
        'created_by' => $manager->id,
    ];
    $invoiceId = DB::table('invoices')->insertGetId([...$invoiceAttributes, 'invoice_number' => 'DS-SALES-MAIN']);
    DB::table('invoices')->insert([...$invoiceAttributes,
        'invoice_number' => 'DS-SALES-OTHER-BRANCH',
        'branch_id' => $otherBranch->id,
        'total' => 999,
    ]);
    DB::table('invoices')->insert([...$invoiceAttributes,
        'invoice_number' => 'DS-SALES-RETURN',
        'type' => InvoiceType::SalesReturn->value,
        'total' => 20,
    ]);
    DB::table('invoices')->insert([...$invoiceAttributes,
        'invoice_number' => 'DS-SALES-DRAFT',
        'status' => InvoiceStatus::Draft->value,
        'total' => 50,
    ]);

    $otherStore = Store::query()->create([
        'code' => 'DASHBOARD-SERVICE-OTHER-STORE',
        'name' => 'فروشگاه دیگر',
        'is_active' => true,
    ]);
    $otherStoreBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'MAIN',
        'name' => 'شعبه فروشگاه دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);
    DB::table('invoices')->insert([
        'store_id' => $otherStore->id,
        'branch_id' => $otherStoreBranch->id,
        'invoice_number' => 'DS-SALES-OTHER-STORE',
        'invoice_date' => $date,
        'type' => InvoiceType::Sales->value,
        'status' => InvoiceStatus::Confirmed->value,
        'total' => 888,
    ]);

    foreach ([[$branch, 1000, 'DS-JOURNAL-MAIN'], [$otherBranch, 900, 'DS-JOURNAL-OTHER']] as [$entryBranch, $amount, $number]) {
        $entryId = DB::table('journal_entries')->insertGetId([
            'store_id' => $store->id,
            'branch_id' => $entryBranch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => $number,
            'entry_date' => $date,
            'description' => 'گردش آزمایشی داشبورد',
            'status' => 'posted',
            'created_by' => $manager->id,
            'posted_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            [
                'journal_entry_id' => $entryId,
                'account_id' => $cash->id,
                'debit' => $amount,
                'credit' => 0,
                'sort_order' => 1,
            ],
            [
                'journal_entry_id' => $entryId,
                'account_id' => $sales->id,
                'debit' => 0,
                'credit' => $amount,
                'sort_order' => 2,
            ],
        ]);
    }

    return compact(
        'store',
        'branch',
        'otherBranch',
        'fiscalYear',
        'manager',
        'product',
        'invoiceId',
        'date',
    );
}

it('builds scoped financial dashboard data directly from the service', function () {
    $context = dashboardServiceContext();

    $dashboard = app(DashboardService::class)->overview(
        $context['manager'],
        $context['store'],
        $context['branch'],
        $context['fiscalYear'],
    );

    expect($dashboard['mode'])->toBe('financial')
        ->and($dashboard['metrics']['cash_balance'])->toBe('1000.00')
        ->and($dashboard['metrics']['sales_today'])->toBe('100.00')
        ->and($dashboard['metrics']['purchases_today'])->toBe('0.00')
        ->and($dashboard['metrics']['open_sales'])->toBe(1)
        ->and($dashboard['metrics']['net_profit'])->toBe('1000.00')
        ->and($dashboard['low_stock']['count'])->toBe(1)
        ->and($dashboard['low_stock']['items'][0]['id'])->toBe($context['product']->id)
        ->and($dashboard['low_stock']['items'][0]['quantity'])->toBe('2.000')
        ->and($dashboard['trend'])->toHaveCount(7)
        ->and($dashboard['trend'][6]['sales'])->toBe('100.00')
        ->and($dashboard['monthly_trend'])->toHaveCount(6)
        ->and(collect($dashboard['recent_activity'])->pluck('id'))->toContain($context['invoiceId'])
        ->and(collect($dashboard['recent_activity'])->pluck('amount'))->not->toContain('999.00')
        ->and(collect($dashboard['recent_activity'])->pluck('amount'))->not->toContain('888.00');
});

it('limits cashier dashboard metrics and activity to scoped sales', function () {
    $context = dashboardServiceContext();
    $cashier = User::factory()->create(['role' => UserRole::Cashier]);

    $dashboard = app(DashboardService::class)->overview(
        $cashier,
        $context['store'],
        $context['branch'],
        $context['fiscalYear'],
    );

    expect($dashboard['mode'])->toBe('sales')
        ->and($dashboard['metrics'])->toBe([
            'sales_today' => '100.00',
            'open_sales' => 1,
            'draft_sales' => 1,
        ])
        ->and(collect($dashboard['trend'])->pluck('purchases')->unique()->all())->toBe(['0.00'])
        ->and(collect($dashboard['recent_activity'])->pluck('kind')->unique()->all())->toBe(['sales'])
        ->and($dashboard['expiry_alerts'])->toBe(['count' => 0, 'items' => []]);
});

it('applies inventory and viewer permissions inside dashboard overviews', function (UserRole $role) {
    $context = dashboardServiceContext();
    $user = User::factory()->create(['role' => $role]);

    $dashboard = app(DashboardService::class)->overview(
        $user,
        $context['store'],
        $context['branch'],
        $context['fiscalYear'],
    );

    expect($user->canViewInventoryReports())->toBe($role === UserRole::Warehouse)
        ->and($dashboard['mode'])->toBe($role === UserRole::Warehouse ? 'inventory' : 'limited')
        ->and($dashboard['metrics'])->toBe([])
        ->and($dashboard['trend'])->toBe([])
        ->and($dashboard['monthly_trend'])->toBe([])
        ->and($dashboard['recent_activity'])->toBe([])
        ->and($dashboard['low_stock']['count'])->toBe($role === UserRole::Warehouse ? 1 : 0)
        ->and($dashboard['expiry_alerts'])->toBe(['count' => 0, 'items' => []]);
})->with([
    'warehouse' => [UserRole::Warehouse],
    'viewer' => [UserRole::Viewer],
]);
