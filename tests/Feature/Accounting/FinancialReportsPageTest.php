<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Reporting\AccountLedgerReportService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function reportPageUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('redirects guests from financial reports', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

it('renders financial reports for a manager', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $manager = reportPageUser(UserRole::Manager);

    $this->actingAs($manager)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->missing('branches')
            ->missing('filters.branch_id')
            ->has('trialBalance.rows')
            ->has('incomeStatement.revenues')
            ->has('balanceSheet.total_assets')
            ->has('journal.data')
            ->has('accounts')
            ->has('accountLedger.rows')
            ->where('accountLedger.rows.total', 0)
            ->where('accountLedger.rows.per_page', 15)
            ->has('accountLedger.rows.data', 0)
            ->where('filters.from', fn ($value) => is_string($value))
            ->where('filters.account_id', fn ($value) => is_int($value))
            ->where('trialBalance.balanced', true));
});

it('forbids cashiers from financial reports', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $cashier = reportPageUser(UserRole::Cashier);

    $this->actingAs($cashier)
        ->get(route('reports.index'))
        ->assertForbidden();
});

it('validates report date ranges', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $manager = reportPageUser(UserRole::Manager);

    $this->actingAs($manager)
        ->get(route('reports.index', [
            'from' => '2026-05-31',
            'to' => '2026-05-01',
        ]))
        ->assertSessionHasErrors('to');
});

it('paginates the account ledger in SQL with balances carried across fifteen-row pages', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(AccountingSetupSeeder::class);
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $year = FiscalYear::query()->where('store_id', $store->id)->where('is_current', true)->firstOrFail();
    $manager = reportPageUser(UserRole::Manager);
    $cash = Account::query()->where('store_id', $store->id)->where('code', '1101')->firstOrFail();
    $bank = Account::query()->where('store_id', $store->id)->where('code', '1102')->firstOrFail();
    $from = $year->starts_on->addDay()->toDateString();
    $period = $year->periods()->orderBy('period_number')->firstOrFail();
    $attributes = [
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $year->id,
        'accounting_period_id' => $period->id,
        'entry_date' => $year->starts_on->toDateString(),
        'created_by' => $manager->id,
        'description' => 'مانده پیش از بازه دفتر معین',
    ];
    app(PostingService::class)->post($attributes, [
        ['account_id' => $cash->id, 'debit' => '100.00', 'credit' => '0.00'],
        ['account_id' => $bank->id, 'debit' => '0.00', 'credit' => '100.00'],
    ], $manager);
    $lines = [];

    for ($index = 0; $index < 32; $index++) {
        $lines[] = [
            'account_id' => $cash->id,
            'debit' => $index < 26 ? '2.25' : '0.00',
            'credit' => $index < 26 ? '0.00' : '1.50',
            'description' => 'گردش '.($index + 1),
        ];
    }

    $lines[] = ['account_id' => $bank->id, 'debit' => '0.00', 'credit' => '49.50'];
    app(PostingService::class)->post([
        ...$attributes, 'entry_date' => $from, 'description' => 'گردش‌های بازه دفتر معین',
    ], $lines, $manager);
    $reports = app(AccountLedgerReportService::class);
    $pages = [];

    foreach ([1, 2, 3] as $page) {
        $pages[] = $reports->pageReport($cash, $store->id, $from, $from, $branch->id, $page);
    }

    foreach ($pages as $report) {
        expect($report['opening_balance'])->toBe('100.00')
            ->and($report['debit_total'])->toBe('58.50')
            ->and($report['credit_total'])->toBe('9.00')
            ->and($report['closing_balance'])->toBe('149.50')
            ->and($report['rows']->total())->toBe(32)
            ->and($report['rows']->perPage())->toBe(15);
    }

    expect($pages[0]['rows']->items())->toHaveCount(15)
        ->and($pages[0]['rows']->items()[14]['balance'])->toBe('133.75')
        ->and($pages[1]['rows']->items())->toHaveCount(15)
        ->and($pages[1]['rows']->items()[0]['balance'])->toBe('136.00')
        ->and($pages[1]['rows']->items()[14]['balance'])->toBe('152.50')
        ->and($pages[2]['rows']->items())->toHaveCount(2)
        ->and($pages[2]['rows']->items()[1]['balance'])->toBe('149.50');
    $allRows = collect($pages)->flatMap(fn (array $report): array => $report['rows']->items());
    $full = $reports->report($cash, $store->id, $from, $from, $branch->id);
    expect($allRows->pluck('id')->unique())->toHaveCount(32)
        ->and($allRows->all())->toBe($full['rows']->all());

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($manager)
        ->get(route('reports.index', [
            'from' => $from, 'to' => $from, 'account_id' => $cash->id, 'ledger_page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.account_id', $cash->id)
            ->where('filters.from', $from)
            ->where('filters.to', $from)
            ->where('accountLedger.opening_balance', '100.00')
            ->where('accountLedger.closing_balance', '149.50')
            ->where('accountLedger.rows.current_page', 2)
            ->where('accountLedger.rows.per_page', 15)
            ->where('accountLedger.rows.total', 32)
            ->where('accountLedger.rows.data.0.balance', '136.00')
            ->has('accountLedger.rows.data', 15)
            ->where('journal.current_page', 1));
    expect(collect(DB::getQueryLog())->pluck('query')->contains(
        fn (string $query): bool => str_contains($query, 'journal_entry_lines')
            && str_contains($query, 'limit 15 offset 15'),
    ))->toBeTrue();
    DB::disableQueryLog();

    $export = $this->get(route('reports.export.financial', [
        'report' => 'ledger', 'from' => $from, 'to' => $from,
        'account_id' => $cash->id, 'ledger_page' => 2,
    ]))->assertOk();
    $content = $export->streamedContent();
    $exportRows = collect(explode("\n", trim($content)))
        ->map(fn (string $line): array => str_getcsv($line, ';', '"', ''));
    expect($exportRows)->toHaveCount(35)
        ->and($exportRows[1][2])->toBe('گردش 1')
        ->and($exportRows[32][2])->toBe('گردش 32')
        ->and($exportRows[32][7])->toBe('149.50');

    $this->get(route('reports.index', ['ledger_page' => 0]))
        ->assertSessionHasErrors('ledger_page');
});
