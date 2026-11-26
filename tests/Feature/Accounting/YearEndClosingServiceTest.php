<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\DocumentType;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @return array<string, mixed>
 */
function yearEndClosingAccounts(Store $store): array
{
    $definitions = [
        '1000' => ['دارایی‌ها', AccountType::Asset, AccountLevel::General, null],
        '1101' => ['صندوق', AccountType::Asset, AccountLevel::Subsidiary, '1000'],
        '3000' => ['سرمایه', AccountType::Equity, AccountLevel::General, null],
        '3102' => ['سود و زیان انباشته', AccountType::Equity, AccountLevel::Subsidiary, '3000'],
        '4000' => ['درآمدها', AccountType::Revenue, AccountLevel::General, null],
        '4101' => ['فروش', AccountType::Revenue, AccountLevel::Subsidiary, '4000'],
        '5000' => ['هزینه‌ها', AccountType::Expense, AccountLevel::General, null],
        '5102' => ['اجاره', AccountType::Expense, AccountLevel::Subsidiary, '5000'],
    ];
    $accounts = [];

    foreach ($definitions as $code => [$name, $type, $level, $parentCode]) {
        $parent = $parentCode === null ? null : $accounts[$parentCode];
        $accounts[$code] = Account::query()->create([
            'store_id' => $store->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'level' => $level,
            'normal_balance' => in_array($type, [AccountType::Asset, AccountType::Expense], true)
                ? NormalBalance::Debit
                : NormalBalance::Credit,
            'is_active' => true,
        ]);
    }

    return $accounts;
}

/**
 * @return array{year: FiscalYear, first_period: AccountingPeriod, last_period: AccountingPeriod}
 */
function yearEndClosingYear(
    Store $store,
    Branch $branch,
    string $title,
    string $startsOn,
    string $endsOn,
    string $firstPeriodEnd,
): array {
    $year = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => $title,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'is_current' => false,
    ]);
    $firstPeriod = AccountingPeriod::query()->create([
        'store_id' => $store->id,
        'fiscal_year_id' => $year->id,
        'name' => 'دوره نخست',
        'period_number' => 1,
        'starts_on' => $startsOn,
        'ends_on' => $firstPeriodEnd,
    ]);
    $lastPeriod = AccountingPeriod::query()->create([
        'store_id' => $store->id,
        'fiscal_year_id' => $year->id,
        'name' => 'دوره پایانی',
        'period_number' => 2,
        'starts_on' => CarbonImmutable::parse($firstPeriodEnd)->addDay()->toDateString(),
        'ends_on' => $endsOn,
    ]);
    DocumentSequence::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $year->id,
        'document_type' => DocumentType::JournalEntry,
        'prefix' => "JE-{$title}-{$branch->code}-",
        'next_number' => 1,
        'padding' => 6,
        'is_active' => true,
    ]);

    return compact('year', 'firstPeriod', 'lastPeriod');
}

/**
 * @return array<string, mixed>
 */
function yearEndClosingServiceContext(string $suffix = 'MAIN'): array
{
    $store = Store::query()->create([
        'code' => "YEAR-END-{$suffix}",
        'name' => 'فروشگاه اختتام سال',
        'is_active' => true,
    ]);
    $branch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'MAIN',
        'name' => 'شعبه اصلی',
        'is_default' => true,
        'is_active' => true,
    ]);
    $secondBranch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'is_active' => true,
    ]);
    $yearData = yearEndClosingYear($store, $branch, '2025', '2025-01-01', '2025-12-31', '2025-06-30');
    DocumentSequence::query()->create([
        'store_id' => $store->id,
        'branch_id' => $secondBranch->id,
        'fiscal_year_id' => $yearData['year']->id,
        'document_type' => DocumentType::JournalEntry,
        'prefix' => "JE-2025-{$secondBranch->code}-",
        'next_number' => 1,
        'padding' => 6,
        'is_active' => true,
    ]);
    $actor = User::factory()->create(['role' => UserRole::Manager]);

    return [
        'store' => $store,
        'branch' => $branch,
        'secondBranch' => $secondBranch,
        'accounts' => yearEndClosingAccounts($store),
        'actor' => $actor,
        ...$yearData,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function yearEndClosingPost(
    Store $store,
    Branch $branch,
    FiscalYear $year,
    AccountingPeriod $period,
    User $actor,
    array $lines,
    string $date,
): JournalEntry {
    return app(PostingService::class)->post(
        [
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $year->id,
            'accounting_period_id' => $period->id,
            'entry_kind' => JournalEntryKind::Manual,
            'entry_date' => $date,
            'description' => 'سند آزمایشی اختتام سال',
            'created_by' => $actor->id,
        ],
        $lines,
        $actor,
    );
}

it('creates balanced branch closings and restores the last period lifecycle', function () {
    $context = yearEndClosingServiceContext();
    $accounts = $context['accounts'];
    $actor = $context['actor'];

    yearEndClosingPost($context['store'], $context['branch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '1000.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '1000.00'],
    ], '2025-03-15');
    yearEndClosingPost($context['store'], $context['branch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['5102']->id, 'debit' => '200.00', 'credit' => '0.00'],
        ['account_id' => $accounts['1101']->id, 'debit' => '0.00', 'credit' => '200.00'],
    ], '2025-04-15');
    yearEndClosingPost($context['store'], $context['secondBranch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '500.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '500.00'],
    ], '2025-05-15');

    $accounts['4101']->update(['is_active' => false]);
    $accounts['5102']->update(['is_active' => false]);
    $closedAt = CarbonImmutable::parse('2025-12-31 23:00:00');
    $context['lastPeriod']->transitionStatus(
        AccountingPeriodStatus::Closed,
        $actor->id,
        $closedAt,
    );

    $entries = app(YearEndClosingService::class)->createClosingEntries(
        $context['store'],
        $context['year'],
        $actor,
        '  اختتام سال مالی  ',
    );

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('branch_id')->all())->toBe([
            $context['branch']->id,
            $context['secondBranch']->id,
        ]);

    $mainClosing = $entries->firstWhere('branch_id', $context['branch']->id);
    $mainLines = $mainClosing->lines->keyBy('account_id');
    $secondClosing = $entries->firstWhere('branch_id', $context['secondBranch']->id);
    $secondRetained = $secondClosing->lines->firstWhere('account_id', $accounts['3102']->id);

    expect($mainClosing->status)->toBe(JournalEntryStatus::Posted)
        ->and($mainClosing->entry_kind)->toBe(JournalEntryKind::Closing)
        ->and($mainClosing->store_id)->toBe($context['store']->id)
        ->and($mainClosing->fiscal_year_id)->toBe($context['year']->id)
        ->and($mainClosing->accounting_period_id)->toBe($context['lastPeriod']->id)
        ->and($mainClosing->entry_date->toDateString())->toBe('2025-12-31')
        ->and($mainClosing->source_type)->toBe($context['year']->getMorphClass())
        ->and($mainClosing->source_id)->toBe($context['year']->id)
        ->and($mainClosing->posted_by)->toBe($actor->id)
        ->and($mainClosing->totalDebit())->toBe('1000.00')
        ->and($mainClosing->totalCredit())->toBe('1000.00')
        ->and($mainLines[$accounts['4101']->id]->debit)->toBe('1000.00')
        ->and($mainLines[$accounts['4101']->id]->credit)->toBe('0.00')
        ->and($mainLines[$accounts['5102']->id]->debit)->toBe('0.00')
        ->and($mainLines[$accounts['5102']->id]->credit)->toBe('200.00')
        ->and($mainLines[$accounts['3102']->id]->debit)->toBe('0.00')
        ->and($mainLines[$accounts['3102']->id]->credit)->toBe('800.00')
        ->and($secondRetained->credit)->toBe('500.00')
        ->and($secondClosing->totalDebit())->toBe($secondClosing->totalCredit())
        ->and($context['lastPeriod']->fresh()->status)->toBe(AccountingPeriodStatus::Closed)
        ->and($context['lastPeriod']->fresh()->closed_by)->toBe($actor->id)
        ->and($context['lastPeriod']->fresh()->closed_at->equalTo($closedAt))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'fiscal_year_closing_posted')->count())->toBe(2)
        ->and(AuditLog::query()
            ->where('event', 'fiscal_year_closing_posted')
            ->where('auditable_id', $mainClosing->id)
            ->value('description'))->toBe('اختتام سال مالی');
});

it('scopes closing totals by store branch and fiscal year', function () {
    $context = yearEndClosingServiceContext();
    $accounts = $context['accounts'];
    $actor = $context['actor'];
    $nextYearData = yearEndClosingYear($context['store'], $context['branch'], '2026', '2026-01-01', '2026-12-31', '2026-06-30');

    yearEndClosingPost($context['store'], $context['branch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '1000.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '1000.00'],
    ], '2025-03-15');
    yearEndClosingPost($context['store'], $context['secondBranch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '75.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '75.00'],
    ], '2025-03-16');
    yearEndClosingPost($context['store'], $context['branch'], $nextYearData['year'], $nextYearData['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '250.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '250.00'],
    ], '2026-03-15');

    $service = app(YearEndClosingService::class);
    $currentClosings = $service->createClosingEntries($context['store'], $context['year'], $actor, 'سال 2025');
    $nextClosings = $service->createClosingEntries($context['store'], $nextYearData['year'], $actor, 'سال 2026');
    $mainClosing = $currentClosings->firstWhere('branch_id', $context['branch']->id);
    $secondClosing = $currentClosings->firstWhere('branch_id', $context['secondBranch']->id);
    $nextClosing = $nextClosings->sole();

    expect($currentClosings)->toHaveCount(2)
        ->and($nextClosings)->toHaveCount(1)
        ->and($mainClosing->lines->firstWhere('account_id', $accounts['3102']->id)->credit)->toBe('1000.00')
        ->and($secondClosing->lines->firstWhere('account_id', $accounts['3102']->id)->credit)->toBe('75.00')
        ->and($nextClosing->lines->firstWhere('account_id', $accounts['3102']->id)->credit)->toBe('250.00')
        ->and($nextClosing->source_id)->toBe($nextYearData['year']->id)
        ->and($nextClosing->accounting_period_id)->toBe($nextYearData['lastPeriod']->id);

    $otherStore = Store::query()->create([
        'code' => 'YEAR-END-FOREIGN-STORE',
        'name' => 'فروشگاه نامعتبر',
        'is_active' => true,
    ]);
    $otherBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'MAIN',
        'name' => 'شعبه نامعتبر',
        'is_default' => true,
        'is_active' => true,
    ]);
    $closingCount = JournalEntry::query()->where('entry_kind', JournalEntryKind::Closing)->count();

    expect(fn () => $service->createClosingEntries($otherStore, $context['year'], $actor, 'دسترسی نامعتبر'))
        ->toThrow(ModelNotFoundException::class)
        ->and(JournalEntry::query()->where('entry_kind', JournalEntryKind::Closing)->count())->toBe($closingCount)
        ->and($otherBranch->fresh()->store_id)->toBe($otherStore->id);
});

it('reuses active closings and reverses them once while preserving the period lifecycle', function () {
    $context = yearEndClosingServiceContext();
    $accounts = $context['accounts'];
    $actor = $context['actor'];
    yearEndClosingPost($context['store'], $context['branch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '1000.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '1000.00'],
    ], '2025-03-15');
    $service = app(YearEndClosingService::class);

    $first = $service->createClosingEntries($context['store'], $context['year'], $actor, 'اختتام اول')->sole();
    $reused = $service->createClosingEntries($context['store'], $context['year'], $actor, 'اختتام تکراری')->sole();

    expect($reused->is($first))->toBeTrue()
        ->and(JournalEntry::query()->where('entry_kind', JournalEntryKind::Closing)->count())->toBe(1);

    $closedAt = CarbonImmutable::parse('2025-12-31 22:00:00');
    $context['lastPeriod']->transitionStatus(AccountingPeriodStatus::Closed, $actor->id, $closedAt);
    $reversal = $service->reverseClosingEntries($context['store'], $context['year'], $actor, '  برگشت اختتام  ')->sole();
    $reversalLines = $reversal->lines->keyBy('account_id');

    expect($reversal->entry_kind)->toBe(JournalEntryKind::Reversal)
        ->and($reversal->status)->toBe(JournalEntryStatus::Posted)
        ->and($reversal->reversal_of_id)->toBe($first->id)
        ->and($reversal->source_type)->toBe($context['year']->getMorphClass())
        ->and($reversal->source_id)->toBe($context['year']->id)
        ->and($reversalLines[$accounts['4101']->id]->debit)->toBe('0.00')
        ->and($reversalLines[$accounts['4101']->id]->credit)->toBe('1000.00')
        ->and($reversalLines[$accounts['3102']->id]->debit)->toBe('1000.00')
        ->and($reversalLines[$accounts['3102']->id]->credit)->toBe('0.00')
        ->and($context['lastPeriod']->fresh()->status)->toBe(AccountingPeriodStatus::Closed)
        ->and($context['lastPeriod']->fresh()->closed_at->equalTo($closedAt))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'fiscal_year_closing_reversed')->count())->toBe(1);

    expect($service->reverseClosingEntries($context['store'], $context['year'], $actor, 'برگشت تکراری'))
        ->toBeEmpty()
        ->and(JournalEntry::query()->where('entry_kind', JournalEntryKind::Reversal)->count())->toBe(1)
        ->and($first->fresh()->reversal)->not->toBeNull()
        ->and(JournalEntry::query()->where('entry_kind', JournalEntryKind::Closing)->count())->toBe(1)
        ->and($context['lastPeriod']->fresh()->status)->toBe(AccountingPeriodStatus::Closed)
        ->and($context['lastPeriod']->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
});

it('rolls back earlier branch closings numbering and audit records when a later branch fails', function () {
    $context = yearEndClosingServiceContext();
    $accounts = $context['accounts'];
    $actor = $context['actor'];
    yearEndClosingPost($context['store'], $context['branch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '1000.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '1000.00'],
    ], '2025-03-15');
    yearEndClosingPost($context['store'], $context['secondBranch'], $context['year'], $context['firstPeriod'], $actor, [
        ['account_id' => $accounts['1101']->id, 'debit' => '200.00', 'credit' => '0.00'],
        ['account_id' => $accounts['4101']->id, 'debit' => '0.00', 'credit' => '200.00'],
    ], '2025-03-16');

    DocumentSequence::query()
        ->where('branch_id', $context['secondBranch']->id)
        ->where('fiscal_year_id', $context['year']->id)
        ->delete();
    $closedAt = CarbonImmutable::parse('2025-12-31 21:00:00');
    $context['lastPeriod']->transitionStatus(AccountingPeriodStatus::Closed, $actor->id, $closedAt);
    $entryCount = JournalEntry::query()->count();
    $lineCount = JournalEntryLine::query()->count();
    $auditCount = AuditLog::query()->count();
    $sequence = DocumentSequence::query()
        ->where('branch_id', $context['branch']->id)
        ->where('fiscal_year_id', $context['year']->id)
        ->firstOrFail();

    expect(fn () => app(YearEndClosingService::class)->createClosingEntries(
        $context['store'],
        $context['year'],
        $actor,
        'اختتام ناموفق',
    ))->toThrow(DomainException::class, 'شماره‌گذاری این نوع سند')
        ->and(JournalEntry::query()->count())->toBe($entryCount)
        ->and(JournalEntryLine::query()->count())->toBe($lineCount)
        ->and(AuditLog::query()->count())->toBe($auditCount)
        ->and(JournalEntry::query()->where('entry_kind', JournalEntryKind::Closing)->exists())->toBeFalse()
        ->and($sequence->fresh()->next_number)->toBe(2)
        ->and($context['lastPeriod']->fresh()->status)->toBe(AccountingPeriodStatus::Closed)
        ->and($context['lastPeriod']->fresh()->closed_by)->toBe($actor->id)
        ->and($context['lastPeriod']->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
});

it('returns no entries without temporary balances and preserves a soft closed period', function () {
    $context = yearEndClosingServiceContext();
    $closedAt = CarbonImmutable::parse('2025-12-31 20:00:00');
    $context['lastPeriod']->transitionStatus(
        AccountingPeriodStatus::SoftClosed,
        $context['actor']->id,
        $closedAt,
    );
    $service = app(YearEndClosingService::class);

    expect($service->createClosingEntries($context['store'], $context['year'], $context['actor'], 'بدون مانده'))
        ->toBeEmpty()
        ->and($service->reverseClosingEntries($context['store'], $context['year'], $context['actor'], 'برگشت بدون مانده'))
        ->toBeEmpty()
        ->and(JournalEntry::query()->whereIn('entry_kind', [
            JournalEntryKind::Closing,
            JournalEntryKind::Reversal,
        ])->exists())->toBeFalse()
        ->and(AuditLog::query()->whereIn('event', [
            'fiscal_year_closing_posted',
            'fiscal_year_closing_reversed',
        ])->exists())->toBeFalse()
        ->and($context['lastPeriod']->fresh()->status)->toBe(AccountingPeriodStatus::SoftClosed)
        ->and($context['lastPeriod']->fresh()->closed_by)->toBe($context['actor']->id)
        ->and($context['lastPeriod']->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
});
