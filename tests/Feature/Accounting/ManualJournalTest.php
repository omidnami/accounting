<?php

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\ManualJournalService;
use App\Services\Accounting\SettingService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     store: Store,
 *     fiscal_year: FiscalYear,
 *     cash: Account,
 *     capital: Account,
 *     sales: Account,
 *     inventory: Account
 * }
 */
function manualJournalContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $fiscalYear = FiscalYear::query()
        ->where('store_id', $store->id)
        ->where('is_current', true)
        ->firstOrFail();
    $settings = app(SettingService::class);
    $capital = Account::query()
        ->where('store_id', $store->id)
        ->where('code', '3101')
        ->firstOrFail();

    return [
        'store' => $store,
        'fiscal_year' => $fiscalYear,
        'cash' => $settings->defaultAccount('cash', $store->id),
        'capital' => $capital,
        'sales' => $settings->defaultAccount('sales', $store->id),
        'inventory' => $settings->defaultAccount('inventory', $store->id),
    ];
}

function manualJournalUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

/**
 * @param  array<int, array{account_id: int, party_id?: int|null, debit: string|int, credit: string|int, description?: string|null}>  $lines
 * @return array<string, mixed>
 */
function manualJournalPayload(
    FiscalYear $fiscalYear,
    string $kind,
    array $lines,
    ?string $entryDate = null,
): array {
    return [
        'entry_kind' => $kind,
        'entry_date' => $entryDate ?? $fiscalYear->starts_on->toDateString(),
        'description' => 'سند آزمایشی دستی',
        'lines' => $lines,
    ];
}

it('creates and posts a balanced manual journal through the official path', function () {
    $context = manualJournalContext();
    $accountant = manualJournalUser(UserRole::Accountant);
    $service = app(ManualJournalService::class);
    $draft = $service->createDraft(
        $context['store'],
        manualJournalPayload($context['fiscal_year'], JournalEntryKind::Manual->value, [
            [
                'account_id' => $context['cash']->id,
                'debit' => 1_250_000,
                'credit' => 0,
            ],
            [
                'account_id' => $context['capital']->id,
                'debit' => 0,
                'credit' => 1_250_000,
            ],
        ]),
        $accountant,
    );

    expect($draft->status)->toBe(JournalEntryStatus::Draft)
        ->and($draft->entry_kind)->toBe(JournalEntryKind::Manual)
        ->and($draft->totalDebit())->toBe('1250000.00')
        ->and($draft->totalCredit())->toBe('1250000.00')
        ->and($draft->entry_number)->toStartWith('JE-');

    $posted = $service->post($context['store'], $draft, $accountant);

    expect($posted->status)->toBe(JournalEntryStatus::Posted)
        ->and($posted->posted_at)->not->toBeNull();
});

it('updates and deletes a manual journal draft', function () {
    $context = manualJournalContext();
    $manager = manualJournalUser(UserRole::Manager);
    $service = app(ManualJournalService::class);
    $draft = $service->createDraft(
        $context['store'],
        manualJournalPayload($context['fiscal_year'], JournalEntryKind::Manual->value, [
            ['account_id' => $context['cash']->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 100],
        ]),
        $manager,
    );

    $updated = $service->updateDraft(
        $context['store'],
        $draft,
        manualJournalPayload(
            $context['fiscal_year'],
            JournalEntryKind::Manual->value,
            [
                ['account_id' => $context['cash']->id, 'debit' => 250, 'credit' => 0],
                ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 250],
            ],
        ),
    );

    expect($updated->totalDebit())->toBe('250.00')
        ->and($updated->lines)->toHaveCount(2);

    $service->deleteDraft($context['store'], $updated);

    expect(JournalEntry::query()->whereKey($updated->id)->exists())->toBeFalse();
});

it('keeps the journal kind immutable after creation', function () {
    $context = manualJournalContext();
    $manager = manualJournalUser(UserRole::Manager);
    $draft = app(ManualJournalService::class)->createDraft(
        $context['store'],
        manualJournalPayload($context['fiscal_year'], JournalEntryKind::Manual->value, [
            ['account_id' => $context['cash']->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 100],
        ]),
        $manager,
    );

    $draft->entry_kind = JournalEntryKind::Opening;
    $draft->save();
})->throws(DomainException::class);

it('enforces opening-balance date, accounts and single-entry rules', function () {
    $context = manualJournalContext();
    $manager = manualJournalUser(UserRole::Manager);
    $service = app(ManualJournalService::class);
    $openingDate = $context['fiscal_year']->starts_on->toDateString();

    expect(fn () => $service->createDraft(
        $context['store'],
        manualJournalPayload(
            $context['fiscal_year'],
            JournalEntryKind::Opening->value,
            [
                ['account_id' => $context['cash']->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $context['sales']->id, 'debit' => 0, 'credit' => 100],
            ],
            $openingDate,
        ),
        $manager,
    ))->toThrow(DomainException::class);

    expect(fn () => $service->createDraft(
        $context['store'],
        manualJournalPayload(
            $context['fiscal_year'],
            JournalEntryKind::Opening->value,
            [
                ['account_id' => $context['inventory']->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 100],
            ],
            $openingDate,
        ),
        $manager,
    ))->toThrow(DomainException::class);

    $opening = $service->createDraft(
        $context['store'],
        manualJournalPayload(
            $context['fiscal_year'],
            JournalEntryKind::Opening->value,
            [
                ['account_id' => $context['cash']->id, 'debit' => 500, 'credit' => 0],
                ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 500],
            ],
            $openingDate,
        ),
        $manager,
    );
    $service->post($context['store'], $opening, $manager);

    expect(fn () => $service->createDraft(
        $context['store'],
        manualJournalPayload(
            $context['fiscal_year'],
            JournalEntryKind::Opening->value,
            [
                ['account_id' => $context['cash']->id, 'debit' => 200, 'credit' => 0],
                ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 200],
            ],
            $openingDate,
        ),
        $manager,
    ))->toThrow(DomainException::class);
});

it('blocks an opening balance after posted activity', function () {
    $context = manualJournalContext();
    $manager = manualJournalUser(UserRole::Manager);
    $service = app(ManualJournalService::class);
    $manual = $service->createDraft(
        $context['store'],
        manualJournalPayload($context['fiscal_year'], JournalEntryKind::Manual->value, [
            ['account_id' => $context['cash']->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 100],
        ]),
        $manager,
    );
    $service->post($context['store'], $manual, $manager);

    expect(fn () => $service->createDraft(
        $context['store'],
        manualJournalPayload(
            $context['fiscal_year'],
            JournalEntryKind::Opening->value,
            [
                ['account_id' => $context['cash']->id, 'debit' => 500, 'credit' => 0],
                ['account_id' => $context['capital']->id, 'debit' => 0, 'credit' => 500],
            ],
        ),
        $manager,
    ))->toThrow(DomainException::class);
});

it('renders the manual journal interface and stores Persian amounts', function () {
    $context = manualJournalContext();
    $manager = manualJournalUser(UserRole::Manager);
    $payload = manualJournalPayload(
        $context['fiscal_year'],
        JournalEntryKind::Manual->value,
        [
            [
                'account_id' => $context['cash']->id,
                'debit' => '۱۲٬۵۰۰٫۰۰',
                'credit' => '۰',
            ],
            [
                'account_id' => $context['capital']->id,
                'debit' => '۰',
                'credit' => '۱۲٬۵۰۰٫۰۰',
            ],
        ],
    );

    $this->actingAs($manager)
        ->get(route('journals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('journals/Index')
            ->has('entries.data')
            ->where('summary.total', 0));

    $this->actingAs($manager)
        ->get(route('journals.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('journals/Form')
            ->has('accounts')
            ->has('parties')
            ->where('default_kind', 'manual'));

    $this->actingAs($manager)
        ->post(route('journals.store'), $payload)
        ->assertRedirect();

    $entry = JournalEntry::query()
        ->where('entry_kind', JournalEntryKind::Manual)
        ->firstOrFail();

    expect($entry->totalDebit())->toBe('12500.00')
        ->and($entry->status)->toBe(JournalEntryStatus::Draft);
});

it('allows managers and accountants and forbids operational roles', function (UserRole $role) {
    manualJournalContext();
    $user = manualJournalUser($role);

    $this->actingAs($user)
        ->get(route('journals.index'))
        ->assertOk();
})->with([
    'manager' => [UserRole::Manager],
    'accountant' => [UserRole::Accountant],
]);

it('forbids cashier and viewer and redirects guests', function (UserRole $role) {
    manualJournalContext();
    $user = manualJournalUser($role);

    $this->get(route('journals.index'))->assertRedirect(route('login'));

    $this->actingAs($user)
        ->get(route('journals.index'))
        ->assertForbidden();
})->with([
    'cashier' => [UserRole::Cashier],
    'viewer' => [UserRole::Viewer],
]);

it('keeps system journals outside the manual journal list', function () {
    $context = manualJournalContext();
    $manager = manualJournalUser(UserRole::Manager);
    JournalEntry::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['store']->defaultBranch->id,
        'entry_number' => 'SYSTEM-ENTRY-1',
        'entry_date' => $context['fiscal_year']->starts_on->toDateString(),
        'fiscal_year_id' => $context['fiscal_year']->id,
        'accounting_period_id' => $context['fiscal_year']->periods()->firstOrFail()->id,
        'description' => 'سند سیستمی',
        'entry_kind' => JournalEntryKind::System->value,
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('journals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('journals/Index')
            ->has('entries.data', 0)
            ->where('summary.total', 0));
});
