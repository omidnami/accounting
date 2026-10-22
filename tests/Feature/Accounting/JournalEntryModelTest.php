<?php

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;

function createJournalAccounts(): array
{
    $assets = Account::query()->create([
        'code' => '1000',
        'name' => 'دارایی‌ها',
        'type' => AccountType::Asset,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $cash = Account::query()->create([
        'parent_id' => $assets->id,
        'code' => '1101',
        'name' => 'صندوق',
        'type' => AccountType::Asset,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $revenues = Account::query()->create([
        'code' => '4000',
        'name' => 'درآمدها',
        'type' => AccountType::Revenue,
        'level' => AccountLevel::General,
        'normal_balance' => NormalBalance::Credit,
    ]);

    $sales = Account::query()->create([
        'parent_id' => $revenues->id,
        'code' => '4101',
        'name' => 'فروش کالا',
        'type' => AccountType::Revenue,
        'level' => AccountLevel::Subsidiary,
        'normal_balance' => NormalBalance::Credit,
    ]);

    return [$cash, $sales];
}

it('creates a balanced journal entry with multiple lines', function () {
    [$cash, $sales] = createJournalAccounts();
    $user = User::factory()->create();

    $entry = JournalEntry::createBalanced(
        [
            'entry_number' => 'JE-0001',
            'entry_date' => '2026-10-22',
            'description' => 'فروش نقدی',
            'created_by' => $user->id,
        ],
        [
            [
                'account_id' => $cash->id,
                'debit' => 1_250_000,
                'credit' => 0,
            ],
            [
                'account_id' => $sales->id,
                'debit' => 0,
                'credit' => 1_250_000,
            ],
        ],
    );

    expect($entry->status)->toBe(JournalEntryStatus::Draft)
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebit())->toBe(1_250_000.0)
        ->and($entry->totalCredit())->toBe(1_250_000.0)
        ->and($entry->creator->is($user))->toBeTrue()
        ->and($cash->journalEntryLines)->toHaveCount(1);
});

it('posts a balanced entry and blocks direct deletion', function () {
    [$cash, $sales] = createJournalAccounts();

    $entry = JournalEntry::createBalanced(
        [
            'entry_number' => 'JE-0002',
            'entry_date' => '2026-10-22',
            'description' => 'ثبت سند فروش',
            'status' => JournalEntryStatus::Posted,
        ],
        [
            ['account_id' => $cash->id, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 500_000],
        ],
    );

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->posted_at)->not->toBeNull();

    $entry->delete();
})->throws(DomainException::class);

it('rolls back an unbalanced journal entry', function () {
    [$cash, $sales] = createJournalAccounts();

    expect(fn () => JournalEntry::createBalanced(
        [
            'entry_number' => 'JE-0003',
            'entry_date' => '2026-10-22',
            'description' => 'سند نامتوازن',
        ],
        [
            ['account_id' => $cash->id, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 450_000],
        ],
    ))->toThrow(DomainException::class);

    expect(JournalEntry::query()->where('entry_number', 'JE-0003')->exists())->toBeFalse();
});

it('rejects a line containing both debit and credit', function () {
    [$cash] = createJournalAccounts();

    $entry = JournalEntry::query()->create([
        'entry_number' => 'JE-0004',
        'entry_date' => '2026-10-22',
        'description' => 'ردیف نامعتبر',
    ]);

    $entry->lines()->create([
        'account_id' => $cash->id,
        'debit' => 100,
        'credit' => 100,
    ]);
})->throws(DomainException::class);

it('does not allow creating a posted entry without controlled posting', function () {
    JournalEntry::query()->create([
        'entry_number' => 'JE-0005',
        'entry_date' => '2026-10-22',
        'description' => 'سند ثبت مستقیم',
        'status' => JournalEntryStatus::Posted,
    ]);
})->throws(DomainException::class);
