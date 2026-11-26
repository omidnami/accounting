<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\InvoiceType;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
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

it('applies the historical correction party policy consistently to lines and final posting', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'INACTIVE-JOURNAL-PARTY',
        'name' => 'طرف‌حساب غیرفعال',
        'is_active' => false,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 0,
        'is_active' => false,
    ]);
    $return = Invoice::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'invoice_number' => 'JE-HISTORICAL-RETURN',
        'type' => InvoiceType::SalesReturn,
        'invoice_date' => '2026-03-22',
        'customer_id' => $customer->id,
    ]);

    $entry = app(PostingService::class)->post(
        [
            ...journalAttributes($context, 'JE-HISTORICAL-1'),
            'entry_kind' => JournalEntryKind::System,
            'source_type' => $return->getMorphClass(),
            'source_id' => $return->id,
        ],
        [
            ['account_id' => $context['cash']->id, 'party_id' => $party->id, 'debit' => '100.00', 'credit' => '0.00'],
            ['account_id' => $context['sales']->id, 'debit' => '0.00', 'credit' => '100.00'],
        ],
        $manager,
    );

    expect($entry->allowsInactiveParty())->toBeTrue()
        ->and($entry->status)->toBe(JournalEntryStatus::Posted);
});

it('rejects inactive parties for new invoices, manual entries, and other-store parties', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'INACTIVE-JOURNAL-REJECT',
        'name' => 'طرف‌حساب رد',
        'is_active' => false,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 0,
        'is_active' => false,
    ]);
    $sale = Invoice::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'invoice_number' => 'JE-NEW-SALE',
        'type' => InvoiceType::Sales,
        'invoice_date' => '2026-03-22',
        'customer_id' => $customer->id,
    ]);
    $lines = [
        ['account_id' => $context['cash']->id, 'party_id' => $party->id, 'debit' => '100.00', 'credit' => '0.00'],
        ['account_id' => $context['sales']->id, 'debit' => '0.00', 'credit' => '100.00'],
    ];

    expect(fn () => app(PostingService::class)->post(
        [
            ...journalAttributes($context, 'JE-REJECT-SALE'),
            'source_type' => $sale->getMorphClass(),
            'source_id' => $sale->id,
        ],
        $lines,
        $manager,
    ))->toThrow(DomainException::class);

    expect(fn () => app(PostingService::class)->post(
        [
            ...journalAttributes($context, 'JE-REJECT-MANUAL'),
            'entry_kind' => JournalEntryKind::Manual,
            'source_type' => $sale->getMorphClass(),
            'source_id' => $sale->id,
        ],
        $lines,
        $manager,
    ))->toThrow(DomainException::class);

    $otherStore = Store::query()->create(['code' => 'OTHER-JOURNAL', 'name' => 'فروشگاه دیگر']);
    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'OTHER-JOURNAL-PARTY',
        'name' => 'طرف‌حساب فروشگاه دیگر',
        'is_active' => false,
    ]);
    $return = Invoice::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'invoice_number' => 'JE-SCOPE-RETURN',
        'type' => InvoiceType::SalesReturn,
        'invoice_date' => '2026-03-22',
        'customer_id' => $customer->id,
    ]);
    $lines[0]['party_id'] = $otherParty->id;

    expect(fn () => app(PostingService::class)->post(
        [
            ...journalAttributes($context, 'JE-REJECT-SCOPE'),
            'source_type' => $return->getMorphClass(),
            'source_id' => $return->id,
        ],
        $lines,
        $manager,
    ))->toThrow(DomainException::class);

    expect(JournalEntry::query()->whereIn('entry_number', [
        'JE-REJECT-SALE',
        'JE-REJECT-MANUAL',
        'JE-REJECT-SCOPE',
    ])->exists())->toBeFalse();
});

it('rejects final posting when a party line has no identity snapshot', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SNAPSHOT-MISSING-PARTY',
        'name' => 'طرف‌حساب بدون snapshot',
    ]);
    $entry = app(PostingService::class)->createDraft(
        journalAttributes($context, 'JE-SNAPSHOT-MISSING'),
        [
            ['account_id' => $context['cash']->id, 'party_id' => $party->id, 'debit' => '100.00', 'credit' => '0.00'],
            ['account_id' => $context['sales']->id, 'debit' => '0.00', 'credit' => '100.00'],
        ],
    );

    expect(fn () => $entry->finalizePosting($manager))
        ->toThrow(DomainException::class, 'snapshot')
        ->and($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
});

it('captures party identity at posting and preserves it in reversals', function () {
    $context = createHardenedJournalContext();
    $manager = createPostingUser(UserRole::Manager);
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SNAPSHOT-JOURNAL-PARTY',
        'name' => 'نام زمان ثبت',
        'phone' => '02100000000',
        'national_id' => '1234567890',
        'version' => 1,
    ]);
    $entry = app(PostingService::class)->post(
        journalAttributes($context, 'JE-SNAPSHOT-1'),
        [
            ['account_id' => $context['cash']->id, 'party_id' => $party->id, 'debit' => '100.00', 'credit' => '0.00'],
            ['account_id' => $context['sales']->id, 'debit' => '0.00', 'credit' => '100.00'],
        ],
        $manager,
    );
    $line = $entry->lines()->whereNotNull('party_id')->firstOrFail();
    $party->update(['name' => 'نام پس از ویرایش', 'phone' => '02111111111']);
    $reversal = app(PostingService::class)->postReversal($entry, $manager, [
        'entry_number' => 'JE-SNAPSHOT-REV',
        'description' => 'معکوس سند snapshot',
    ]);
    $reversalLine = $reversal->lines()->whereNotNull('party_id')->firstOrFail();

    expect($line->party_snapshot['name'])->toBe('نام زمان ثبت')
        ->and($line->party_snapshot['phone'])->toBe('02100000000')
        ->and($line->party_snapshot['party_version'])->toBe(1)
        ->and($reversalLine->party_snapshot)->toBe($line->party_snapshot);
});
