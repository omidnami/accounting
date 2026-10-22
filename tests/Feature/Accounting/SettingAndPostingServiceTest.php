<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\DocumentType;
use App\Enums\JournalEntryStatus;
use App\Enums\UserRole;
use App\Models\AccountingPeriod;
use App\Models\DocumentSequence;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use Database\Seeders\AccountSeeder;
use Illuminate\Auth\Access\AuthorizationException;

function createPostingContext(): array
{
    app(AccountSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $fiscalYear = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => '1405',
        'starts_on' => '2026-03-21',
        'ends_on' => '2027-03-20',
        'is_current' => true,
    ]);
    $period = AccountingPeriod::query()->create([
        'store_id' => $store->id,
        'fiscal_year_id' => $fiscalYear->id,
        'name' => 'فروردین',
        'period_number' => 1,
        'starts_on' => '2026-03-21',
        'ends_on' => '2026-04-20',
        'status' => AccountingPeriodStatus::Open,
    ]);

    return compact('store', 'branch', 'fiscalYear', 'period');
}

it('resolves default accounts through the setting service', function () {
    $this->seed(AccountSeeder::class);
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();

    $service = app(SettingService::class);

    expect($service->defaultAccount('cash', $store->id)->code)->toBe('1101')
        ->and($service->defaultAccount('sales', $store->id)->code)->toBe('4101')
        ->and($service->defaultAccountId('cogs', $store->id))
        ->toBe($service->defaultAccount('cogs', $store->id)->id);
});

it('allocates a journal number from the configured sequence', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear, 'period' => $period] = createPostingContext();
    $cash = app(SettingService::class)->defaultAccount('cash', $store->id);
    $sales = app(SettingService::class)->defaultAccount('sales', $store->id);
    DocumentSequence::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $fiscalYear->id,
        'document_type' => DocumentType::JournalEntry,
        'prefix' => 'JE-1405-',
        'next_number' => 1,
        'padding' => 6,
    ]);

    $entry = app(PostingService::class)->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_date' => '2026-03-22',
            'description' => 'سند شماره‌گذاری خودکار',
        ],
        [
            ['account_id' => $cash->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );

    expect($entry->entry_number)->toBe('JE-1405-000001')
        ->and(DocumentSequence::query()->first()->next_number)->toBe(2);
});

it('posts a balanced entry only with an authorized role and open period', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear, 'period' => $period] = createPostingContext();
    $cash = app(SettingService::class)->defaultAccount('cash', $store->id);
    $sales = app(SettingService::class)->defaultAccount('sales', $store->id);
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();

    $entry = app(PostingService::class)->post(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-TEST-001',
            'entry_date' => '2026-03-22',
            'description' => 'فروش نقدی آزمایشی',
            'created_by' => $manager->id,
        ],
        [
            ['account_id' => $cash->id, 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1_000_000],
        ],
        $manager,
    );

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->branch->is($branch))->toBeTrue()
        ->and($entry->fiscalYear->is($fiscalYear))->toBeTrue()
        ->and($entry->accountingPeriod->is($period))->toBeTrue()
        ->and($store->journalEntries)->toHaveCount(1);
});

it('rejects journal approval by a cashier', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear, 'period' => $period] = createPostingContext();
    $cash = app(SettingService::class)->defaultAccount('cash', $store->id);
    $sales = app(SettingService::class)->defaultAccount('sales', $store->id);
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();

    $entry = app(PostingService::class)->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-TEST-002',
            'entry_date' => '2026-03-22',
            'description' => 'سند بدون مجوز',
        ],
        [
            ['account_id' => $cash->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );

    app(PostingService::class)->approveAndPost($entry, $cashier);
})->throws(AuthorizationException::class);

it('rejects posting into a closed accounting period', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear, 'period' => $period] = createPostingContext();
    $period->forceFill(['status' => AccountingPeriodStatus::Closed])->save();
    $cash = app(SettingService::class)->defaultAccount('cash', $store->id);
    $sales = app(SettingService::class)->defaultAccount('sales', $store->id);
    $accountant = User::factory()->create();
    $accountant->forceFill(['role' => UserRole::Accountant])->save();

    $entry = app(PostingService::class)->createDraft(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-TEST-003',
            'entry_date' => '2026-03-22',
            'description' => 'سند دوره بسته',
        ],
        [
            ['account_id' => $cash->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );

    app(PostingService::class)->approveAndPost($entry, $accountant);
})->throws(DomainException::class);
