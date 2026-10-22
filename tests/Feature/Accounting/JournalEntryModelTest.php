<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use Database\Seeders\AccountSeeder;

function createHardenedJournalContext(): array
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
    $cash = app(SettingService::class)->defaultAccount('cash', $store->id);
    $sales = app(SettingService::class)->defaultAccount('sales', $store->id);

    return compact('store', 'branch', 'fiscalYear', 'period', 'cash', 'sales');
}

function createPostingUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

function journalAttributes(
    array $context,
    string $entryNumber,
    string $entryDate = '2026-03-22',
): array {
    return [
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'accounting_period_id' => $context['period']->id,
        'entry_number' => $entryNumber,
        'entry_date' => $entryDate,
        'description' => 'سند آزمایشی',
    ];
}

it('creates a balanced draft with decimal totals', function () {
    $context = createHardenedJournalContext();
    $user = createPostingUser(UserRole::Accountant);

    $entry = app(PostingService::class)->createDraft(
        [
            ...journalAttributes($context, 'JE-0001'),
            'created_by' => $user->id,
        ],
        [
            ['account_id' => $context['cash']->id, 'debit' => '1250000.00', 'credit' => '0.00'],
            ['account_id' => $context['sales']->id, 'debit' => '0.00', 'credit' => '1250000.00'],
        ],
    );

    expect($entry->status)->toBe(JournalEntryStatus::Draft)
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebit())->toBe('1250000.00')
        ->and($entry->totalCredit())->toBe('1250000.00')
        ->and($entry->creator->is($user))->toBeTrue();
});

it('posts a balanced entry through the controlled domain transition', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);

    $entry = app(PostingService::class)->post(
        journalAttributes($context, 'JE-0002'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 500_000],
        ],
        $manager,
    );

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->posted_at)->not->toBeNull();
});

it('blocks every header edit after posting', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $entry = app(PostingService::class)->post(
        journalAttributes($context, 'JE-0003'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
        $manager,
    );

    $entry->update(['description' => 'تلاش برای ویرایش']);
})->throws(DomainException::class);

it('blocks deleting a posted entry', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $entry = app(PostingService::class)->post(
        journalAttributes($context, 'JE-0004'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
        $manager,
    );

    $entry->delete();
})->throws(DomainException::class);

it('cannot bypass posting with a direct status update', function () {
    $context = createHardenedJournalContext();
    $entry = app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-0005'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );

    $entry->status = JournalEntryStatus::Posted;
    $entry->posted_at = now();
    $entry->save();
})->throws(DomainException::class);

it('rolls back an unbalanced journal entry', function () {
    $context = createHardenedJournalContext();

    expect(fn () => app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-0006'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 450_000],
        ],
    ))->toThrow(DomainException::class);

    expect(JournalEntry::query()->where('entry_number', 'JE-0006')->exists())->toBeFalse();
});

it('uses fresh locked lines instead of a stale loaded relation', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $entry = app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-0007'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );
    $entry->load('lines');
    $entry->lines->last()->delete();

    app(PostingService::class)->approveAndPost($entry, $manager);
})->throws(DomainException::class);

it('rejects a posting date outside the selected period', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $entry = app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-0008', '2026-05-01'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );

    app(PostingService::class)->approveAndPost($entry, $manager);
})->throws(DomainException::class);

it('rechecks that every account is still active at final posting', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $entry = app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-0010'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );
    $context['cash']->update(['is_active' => false]);

    expect(fn () => app(PostingService::class)->approveAndPost($entry, $manager))
        ->toThrow(DomainException::class)
        ->and($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
});

it('rejects a line account from another store', function () {
    $context = createHardenedJournalContext();
    $otherStore = Store::query()->create([
        'code' => 'OTHER-STORE',
        'name' => 'فروشگاه دیگر',
    ]);
    $otherGeneral = Account::query()->create([
        'store_id' => $otherStore->id,
        'code' => '9900',
        'name' => 'دارایی‌ها',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);
    $otherCash = Account::query()->create([
        'store_id' => $otherStore->id,
        'parent_id' => $otherGeneral->id,
        'code' => '9911',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);
    $entry = app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-0009'),
        [
            ['account_id' => $context['cash']->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100_000],
        ],
    );

    $entry->lines()->create([
        'account_id' => $otherCash->id,
        'debit' => 1,
        'credit' => 0,
    ]);
})->throws(DomainException::class);
