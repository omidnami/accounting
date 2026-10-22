<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Store;
use Carbon\CarbonImmutable;

function createFoundation(): array
{
    $store = Store::query()->create([
        'code' => 'STORE-001',
        'name' => 'فروشگاه آزمایشی',
    ]);

    $branch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'BR-001',
        'name' => 'شعبه اصلی',
        'is_default' => true,
    ]);

    $fiscalYear = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => '1405',
        'starts_on' => '2026-03-21',
        'ends_on' => '2027-03-20',
        'status' => FiscalYearStatus::Open,
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

it('connects stores, branches, fiscal years, and accounting periods', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear, 'period' => $period] = createFoundation();

    expect($store->branches)->toHaveCount(1)
        ->and($store->defaultBranch->is($branch))->toBeTrue()
        ->and($branch->store->is($store))->toBeTrue()
        ->and($fiscalYear->store->is($store))->toBeTrue()
        ->and($fiscalYear->periods)->toHaveCount(1)
        ->and($period->fiscalYear->is($fiscalYear))->toBeTrue()
        ->and($period->status)->toBe(AccountingPeriodStatus::Open)
        ->and($period->isOpen())->toBeTrue();
});

it('rejects an accounting period outside its fiscal year', function () {
    ['store' => $store, 'fiscalYear' => $fiscalYear] = createFoundation();

    AccountingPeriod::query()->create([
        'store_id' => $store->id,
        'fiscal_year_id' => $fiscalYear->id,
        'name' => 'دوره نامعتبر',
        'period_number' => 13,
        'starts_on' => '2027-03-21',
        'ends_on' => '2027-04-20',
    ]);
})->throws(DomainException::class);

it('keeps only one current fiscal year per store', function () {
    ['store' => $store, 'fiscalYear' => $firstYear] = createFoundation();

    $nextYear = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => '1406',
        'starts_on' => '2027-03-21',
        'ends_on' => '2028-03-20',
        'is_current' => true,
    ]);

    expect($firstYear->fresh()->is_current)->toBeFalse()
        ->and($nextYear->fresh()->is_current)->toBeTrue()
        ->and($nextYear->starts_on)->toBeInstanceOf(CarbonImmutable::class);
});

it('attaches operational documents to a store, branch, and fiscal year', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear] = createFoundation();

    $invoice = Invoice::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $fiscalYear->id,
        'invoice_number' => 'S-BRANCH-001',
        'type' => InvoiceType::Sales,
        'invoice_date' => '2026-03-22',
        'customer_id' => null,
    ]);
})->throws(DomainException::class);

it('exposes store and branch relations on invoices', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear] = createFoundation();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'C-BRANCH-001',
        'name' => 'مشتری شعبه',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
    ]);

    $invoice = Invoice::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $fiscalYear->id,
        'invoice_number' => 'S-BRANCH-002',
        'type' => InvoiceType::Sales,
        'invoice_date' => '2026-03-22',
        'customer_id' => $customer->id,
    ]);

    expect($invoice->store->is($store))->toBeTrue()
        ->and($invoice->branch->is($branch))->toBeTrue()
        ->and($invoice->fiscalYear->is($fiscalYear))->toBeTrue()
        ->and($branch->invoices)->toHaveCount(1);
});
