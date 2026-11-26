<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reporting\ReportExportService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array<string, mixed>
 */
function reportExportServiceContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $warehouseUser = User::factory()->create(['role' => UserRole::Warehouse]);
    $cashier = User::factory()->create(['role' => UserRole::Cashier]);
    $date = today()->toDateString();
    $period = $fiscalYear->periods()
        ->where('starts_on', '<=', $date)
        ->where('ends_on', '>=', $date)
        ->firstOrFail();
    $cash = Account::query()->where('store_id', $store->id)->where('code', '1101')->firstOrFail();
    $sales = Account::query()->where('store_id', $store->id)->where('code', '4101')->firstOrFail();
    $otherBranch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'EXPORT-OTHER',
        'name' => 'شعبه دیگر گزارش',
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

    foreach ([[$branch, 250, 'EXPORT-JOURNAL-MAIN'], [$otherBranch, 900, 'EXPORT-JOURNAL-OTHER']] as [$entryBranch, $amount, $number]) {
        $entryId = DB::table('journal_entries')->insertGetId([
            'store_id' => $store->id,
            'branch_id' => $entryBranch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => $number,
            'entry_date' => $date,
            'description' => 'گردش گزارش خروجی',
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

    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'EXPORT-CUSTOMER',
        'name' => 'مشتری خروجی',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);
    $otherStore = Store::query()->create([
        'code' => 'EXPORT-OTHER-STORE',
        'name' => 'فروشگاه دیگر خروجی',
        'is_active' => true,
    ]);
    $otherStoreBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'MAIN',
        'name' => 'شعبه فروشگاه دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);
    $foreignWarehouse = Warehouse::query()->create([
        'store_id' => $otherStore->id,
        'branch_id' => $otherStoreBranch->id,
        'code' => 'MAIN',
        'name' => 'انبار فروشگاه دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);
    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'EXPORT-OTHER-CUSTOMER',
        'name' => 'مشتری فروشگاه دیگر',
        'is_active' => true,
    ]);
    Customer::query()->create([
        'party_id' => $otherParty->id,
        'is_active' => true,
    ]);

    DB::table('invoices')->insert([
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'invoice_number' => 'EXPORT-AGING-MAIN',
            'invoice_date' => $date,
            'due_date' => today()->subDays(10)->toDateString(),
            'type' => 'sales',
            'status' => 'confirmed',
            'customer_id' => $customer->id,
            'total' => 125,
        ],
        [
            'store_id' => $store->id,
            'branch_id' => $otherBranch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'invoice_number' => 'EXPORT-AGING-OTHER-BRANCH',
            'invoice_date' => $date,
            'due_date' => today()->subDays(10)->toDateString(),
            'type' => 'sales',
            'status' => 'confirmed',
            'customer_id' => $customer->id,
            'total' => 900,
        ],
        [
            'store_id' => $otherStore->id,
            'branch_id' => $otherStoreBranch->id,
            'fiscal_year_id' => null,
            'invoice_number' => 'EXPORT-AGING-OTHER-STORE',
            'invoice_date' => $date,
            'due_date' => today()->subDays(10)->toDateString(),
            'type' => 'sales',
            'status' => 'confirmed',
            'customer_id' => Customer::query()->whereHas('party', fn ($query) => $query->where('id', $otherParty->id))->value('id'),
            'total' => 700,
        ],
    ]);

    $product = Product::query()->create([
        'code' => 'EXPORT-CARDEX-PRODUCT',
        'name' => 'کالای کاردکس خروجی',
        'purchase_price' => 100,
        'sale_price' => 200,
        'is_active' => true,
    ]);
    foreach ([[$branch, $warehouse, 5, 'EXPORT-CARDEX-MAIN'], [$otherBranch, $otherWarehouse, 50, 'EXPORT-CARDEX-OTHER']] as [$movementBranch, $movementWarehouse, $quantity, $reference]) {
        DB::table('inventory_movements')->insert([
            'store_id' => $store->id,
            'branch_id' => $movementBranch->id,
            'warehouse_id' => $movementWarehouse->id,
            'product_id' => $product->id,
            'direction' => 'in',
            'movement_type' => 'opening',
            'quantity' => $quantity,
            'unit_cost' => 100,
            'total_cost' => $quantity * 100,
            'quantity_after' => $quantity,
            'average_cost_after' => 100,
            'value_after' => $quantity * 100,
            'movement_date' => $date,
            'idempotency_key' => $reference,
            'reference' => $reference,
            'description' => 'گردش کاردکس',
        ]);
    }

    return compact(
        'store',
        'branch',
        'otherBranch',
        'warehouse',
        'foreignWarehouse',
        'fiscalYear',
        'manager',
        'warehouseUser',
        'cashier',
        'date',
        'party',
        'otherParty',
        'product',
    );
}

/**
 * @return Collection<int, array<int, string>>
 */
function reportExportCsvRows(Response $response): Collection
{
    $content = (new TestResponse($response))->streamedContent();

    return collect(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))))
        ->map(fn (string $line): array => str_getcsv($line, ';', '"', ''));
}

it('exports branch-scoped financial report data directly as CSV', function () {
    $context = reportExportServiceContext();

    $response = app(ReportExportService::class)->financial(
        $context['store'],
        'trial',
        $context['date'],
        $context['date'],
        $context['branch']->id,
        null,
    );
    $rows = reportExportCsvRows($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toContain('trial-balance-'.$context['date'].'-'.$context['date'].'.csv')
        ->and($rows->first()[0])->toBe('کد حساب')
        ->and(collect($rows->flatten()))->toContain('250.00')
        ->and(collect($rows->flatten()))->not->toContain('900.00')
        ->and(collect($rows->flatten()))->toContain('کنترل: متوازن')
        ->and($context['manager']->canViewFinancialReports())->toBeTrue()
        ->and($context['cashier']->canViewFinancialReports())->toBeFalse();
});

it('exports scoped aging rows and rejects parties from another store', function () {
    $context = reportExportServiceContext();
    $service = app(ReportExportService::class);

    $response = $service->operations(
        $context['store'],
        'aging_receivable',
        $context['date'],
        $context['date'],
        $context['branch']->id,
        null,
    );
    $rows = reportExportCsvRows($response)->skip(1);
    $invoice = $rows->firstWhere(2, 'EXPORT-AGING-MAIN');

    expect($rows)->toHaveCount(2)
        ->and($invoice[0])->toBe('مشتری خروجی')
        ->and($invoice[1])->toBe('EXPORT-CUSTOMER')
        ->and($invoice[5])->toBe('125.00')
        ->and($invoice[6])->toBe('10')
        ->and($invoice[7])->toBe('days_1_30')
        ->and($rows->last()[0])->toBe('جمع کل')
        ->and($rows->last()[5])->toBe('125.00')
        ->and(collect($rows->flatten()))->not->toContain('EXPORT-AGING-OTHER-BRANCH')
        ->and(collect($rows->flatten()))->not->toContain('EXPORT-AGING-OTHER-STORE');

    expect(fn () => $service->operations(
        $context['store'],
        'statement',
        $context['date'],
        $context['date'],
        $context['branch']->id,
        $context['otherParty']->id,
    ))->toThrow(ModelNotFoundException::class);
});

it('exports scoped cardex rows and applies inventory cost permissions to columns', function () {
    $context = reportExportServiceContext();
    $service = app(ReportExportService::class);

    $financial = $service->cardex(
        $context['store'],
        $context['product']->id,
        $context['warehouse']->id,
        $context['date'],
        $context['date'],
        null,
        null,
        $context['manager']->canViewInventoryCosts(),
    );
    $financialRows = reportExportCsvRows($financial);

    $operational = $service->cardex(
        $context['store'],
        $context['product']->id,
        $context['warehouse']->id,
        $context['date'],
        $context['date'],
        null,
        null,
        $context['warehouseUser']->canViewInventoryCosts(),
    );
    $operationalRows = reportExportCsvRows($operational);

    expect($financialRows)->toHaveCount(2)
        ->and($financialRows[0])->toContain('بهای واحد')
        ->and($financialRows[0])->toContain('ارزش پس از گردش')
        ->and($financialRows[1][5])->toBe('EXPORT-CARDEX-MAIN')
        ->and($financialRows[1][6])->toBe('100.0000')
        ->and($financialRows[1][7])->toBe('500.00')
        ->and($financialRows[1][8])->toBe('500.00')
        ->and($financialRows[1][9])->toBe('5.000')
        ->and($operationalRows[0])->not->toContain('بهای واحد')
        ->and($operationalRows[0])->not->toContain('بهای کل')
        ->and($operationalRows[1][5])->toBe('EXPORT-CARDEX-MAIN')
        ->and($operationalRows[1][6])->toBe('5.000')
        ->and(collect($financialRows->flatten()))->not->toContain('EXPORT-CARDEX-OTHER')
        ->and($context['manager']->canViewInventoryCosts())->toBeTrue()
        ->and($context['warehouseUser']->canViewInventoryCosts())->toBeFalse();

    expect(fn () => $service->cardex(
        $context['store'],
        $context['product']->id,
        $context['foreignWarehouse']->id,
        $context['date'],
        $context['date'],
        null,
        null,
        false,
    ))->toThrow(ModelNotFoundException::class);
});
