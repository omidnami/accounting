<?php

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Branches\BranchContextService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

function branchScopeContext(): array
{
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'BRANCH-SCOPE-CUSTOMER',
        'name' => 'Customer',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $second = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'SECOND',
        'name' => 'Second branch',
        'is_active' => true,
    ]);
    app(FiscalYearSetupService::class)->ensureSequences($year);

    return compact('store', 'year', 'manager', 'customer', 'second');
}

it('creates document sequences for every active branch', function () {
    $context = branchScopeContext();

    expect(DocumentSequence::query()->where('fiscal_year_id', $context['year']->id)->count())
        ->toBe(count(DocumentType::cases()) * 2);
});

it('allows the same document number in different branches but not within one branch', function () {
    $context = branchScopeContext();
    $number = app(DocumentNumberService::class)->next(
        $context['store']->id,
        $context['second']->id,
        $context['year']->id,
        DocumentType::SalesInvoice,
    );
    $default = $context['store']->defaultBranch;
    $attributes = [
        'store_id' => $context['store']->id,
        'fiscal_year_id' => $context['year']->id,
        'invoice_number' => $number,
        'customer_id' => $context['customer']->id,
        'invoice_date' => $context['year']->starts_on->toDateString(),
        'type' => InvoiceType::Sales,
        'status' => InvoiceStatus::Draft,
        'created_by' => $context['manager']->id,
    ];
    Invoice::query()->create([...$attributes, 'branch_id' => $default->id]);
    Invoice::query()->create([...$attributes, 'branch_id' => $context['second']->id]);

    expect(Invoice::query()->where('invoice_number', $number)->count())->toBe(2);

    Invoice::query()->create([...$attributes, 'branch_id' => $context['second']->id]);
})->throws(UniqueConstraintViolationException::class);

it('resolves only the assigned active branch for a user', function () {
    $context = branchScopeContext();
    $user = User::factory()->create([
        'role' => UserRole::Warehouse,
        'branch_id' => $context['second']->id,
    ]);

    expect(app(BranchContextService::class)->active($user, $context['store'])->id)
        ->toBe($context['second']->id);

    $context['second']->update(['is_active' => false]);

    app(BranchContextService::class)->active($user, $context['store']);
})->throws(DomainException::class, 'شعبهٔ فعالی');
