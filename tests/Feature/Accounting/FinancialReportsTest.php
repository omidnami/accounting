<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\PostingService;
use App\Services\Reporting\AccountLedgerReportService;
use App\Services\Reporting\CashFlowReportService;
use App\Services\Reporting\FinancialStatementReportService;
use App\Services\Reporting\GeneralLedgerReportService;
use App\Services\Reporting\JournalReportService;
use App\Services\Reporting\TrialBalanceReportService;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia;

function createReportContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $fiscalYear = FiscalYear::query()->where('store_id', $store->id)->where('title', '1405')->first()
        ?? app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $period = $fiscalYear->periods()->where('starts_on', '<=', '2026-05-01')->where('ends_on', '>=', '2026-05-01')->firstOrFail();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    $cash = Account::query()->where('store_id', $store->id)->where('code', '1101')->firstOrFail();
    $sales = Account::query()->where('store_id', $store->id)->where('code', '4101')->firstOrFail();
    $rent = Account::query()->where('store_id', $store->id)->where('code', '5102')->firstOrFail();
    $posting = app(PostingService::class);

    foreach ([
        ['REP-001', '2026-05-01', 1000, 'فروش اول'],
        ['REP-002', '2026-05-05', 500, 'فروش دوم'],
        ['REP-003', '2026-05-15', 200, 'هزینه اجاره'],
    ] as [$number, $date, $amount, $description]) {
        $isSale = $number !== 'REP-003';
        $posting->post(
            [
                'store_id' => $store->id,
                'branch_id' => $branch->id,
                'fiscal_year_id' => $fiscalYear->id,
                'accounting_period_id' => $period->id,
                'entry_number' => $number,
                'entry_date' => $date,
                'description' => $description,
                'created_by' => $manager->id,
            ],
            [
                [
                    'account_id' => $isSale ? $cash->id : $rent->id,
                    'debit' => $amount,
                    'credit' => 0,
                ],
                [
                    'account_id' => $isSale ? $sales->id : $cash->id,
                    'debit' => 0,
                    'credit' => $amount,
                ],
            ],
            $manager,
        );
    }

    return compact('store', 'branch', 'fiscalYear', 'cash', 'sales', 'rent');
}

it('returns posted journal entries within the report range', function () {
    $context = createReportContext();
    $entries = app(JournalReportService::class)->entries(
        $context['store']->id,
        '2026-05-01',
        '2026-05-31',
        $context['branch']->id,
    );

    expect($entries)->toHaveCount(3)
        ->and($entries->first()->entry_number)->toBe('REP-001');
});

it('paginates the journal report', function () {
    $context = createReportContext();
    $service = app(JournalReportService::class);
    $firstPage = $service->paginate(
        $context['store']->id,
        '2026-05-01',
        '2026-05-31',
        $context['branch']->id,
        2,
    );
    $secondPage = $service->paginate(
        $context['store']->id,
        '2026-05-01',
        '2026-05-31',
        $context['branch']->id,
        2,
        page: 2,
    );

    expect($firstPage->total())->toBe(3)
        ->and($firstPage->items())->toHaveCount(2)
        ->and($secondPage->items())->toHaveCount(1);
});

it('builds an account ledger with opening and running balances', function () {
    $context = createReportContext();
    $ledger = app(AccountLedgerReportService::class)->report(
        $context['cash'],
        $context['store']->id,
        '2026-05-10',
        '2026-05-31',
        $context['branch']->id,
    );

    expect($ledger['opening_balance'])->toBe('1500.00')
        ->and($ledger['debit_total'])->toBe('0.00')
        ->and($ledger['credit_total'])->toBe('200.00')
        ->and($ledger['closing_balance'])->toBe('1300.00')
        ->and($ledger['rows'])->toHaveCount(1);
});

it('aggregates subsidiary activity for a general account ledger', function () {
    $context = createReportContext();
    $assets = $context['cash']->parent;
    $ledger = app(AccountLedgerReportService::class)->report(
        $assets,
        $context['store']->id,
        '2026-05-10',
        '2026-05-31',
        $context['branch']->id,
    );

    expect($ledger['opening_balance'])->toBe('1500.00')
        ->and($ledger['credit_total'])->toBe('200.00')
        ->and($ledger['closing_balance'])->toBe('1300.00');
});

it('builds a balanced trial balance', function () {
    $context = createReportContext();
    $report = app(TrialBalanceReportService::class)->report(
        $context['store']->id,
        '2026-05-10',
        '2026-05-31',
        $context['branch']->id,
    );

    expect($report['balanced'])->toBeTrue()
        ->and($report['total_debit'])->toBe('200.00')
        ->and($report['total_credit'])->toBe('200.00')
        ->and($report['total_closing_debit'])->toBe('1500.00')
        ->and($report['total_closing_credit'])->toBe('1500.00');
});

it('builds income statement and balanced balance sheet', function () {
    $context = createReportContext();
    $statements = app(FinancialStatementReportService::class);
    $income = $statements->incomeStatement(
        $context['store']->id,
        '2026-05-01',
        '2026-05-31',
        $context['branch']->id,
    );
    $balance = $statements->balanceSheet(
        $context['store']->id,
        '2026-05-31',
        $context['branch']->id,
    );

    expect($income['total_revenue'])->toBe('1500.00')
        ->and($income['total_expense'])->toBe('200.00')
        ->and($income['net_profit'])->toBe('1300.00')
        ->and($balance['total_assets'])->toBe('1300.00')
        ->and($balance['total_equity'])->toBe('1300.00')
        ->and($balance['balanced'])->toBeTrue();
});

it('paginates the general ledger and totals all posted lines', function () {
    $context = createReportContext();
    $service = app(GeneralLedgerReportService::class);
    $first = $service->pageReport(
        $context['store']->id,
        '2026-05-01',
        '2026-05-31',
        $context['branch']->id,
        1,
        4,
    );
    $second = $service->pageReport(
        $context['store']->id,
        '2026-05-01',
        '2026-05-31',
        $context['branch']->id,
        2,
        4,
    );

    expect($first['debit_total'])->toBe('1700.00')
        ->and($first['credit_total'])->toBe('1700.00')
        ->and($first['rows']['total'])->toBe(6)
        ->and($first['rows']['data'])->toHaveCount(4)
        ->and($second['rows']['data'])->toHaveCount(2)
        ->and($second['rows']['current_page'])->toBe(2);
});

it('builds cash flow from cash and bank accounts with opening and closing balances', function () {
    $context = createReportContext();
    $cashFlow = app(CashFlowReportService::class)->report(
        $context['store']->id,
        '2026-05-10',
        '2026-05-31',
        $context['branch']->id,
    );

    expect($cashFlow['opening_balance'])->toBe('1500.00')
        ->and($cashFlow['inflow_total'])->toBe('0.00')
        ->and($cashFlow['outflow_total'])->toBe('200.00')
        ->and($cashFlow['net_change'])->toBe('-200.00')
        ->and($cashFlow['closing_balance'])->toBe('1300.00')
        ->and($cashFlow['rows'][0]['period'])->toBe('2026-05')
        ->and($cashFlow['rows'][0]['net'])->toBe('-200.00');
});

it('exposes general ledger and cash flow on the financial reports page', function () {
    $context = createReportContext();

    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->get(route('reports.index', [
            'from' => '2026-05-01',
            'to' => '2026-05-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reports/Index')
            ->where('generalLedger.debit_total', '1700.00')
            ->where('generalLedger.rows.total', 6)
            ->where('cashFlow.opening_balance', '0.00')
            ->where('cashFlow.net_change', '1300.00')
            ->where('cashFlow.closing_balance', '1300.00'));
});

it('rejects invalid date ranges in financial reports', function () {
    $context = createReportContext();
    $trial = app(TrialBalanceReportService::class);
    $statements = app(FinancialStatementReportService::class);

    expect(fn () => $trial->report(
        $context['store']->id,
        '2026-05-31',
        '2026-05-01',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $statements->incomeStatement(
        $context['store']->id,
        '2026-05-31',
        '2026-05-01',
    ))->toThrow(InvalidArgumentException::class);
});
