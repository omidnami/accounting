<?php

use App\Enums\DocumentType;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\DocumentNumberService;

function createSequenceContext(): array
{
    $store = Store::query()->create([
        'code' => 'SEQ-STORE',
        'name' => 'فروشگاه شماره‌گذاری',
    ]);

    $branch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'SEQ-BRANCH',
        'name' => 'شعبه شماره‌گذاری',
        'is_default' => true,
    ]);

    $fiscalYear = FiscalYear::query()->create([
        'store_id' => $store->id,
        'title' => '1405',
        'starts_on' => '2026-03-21',
        'ends_on' => '2027-03-20',
        'is_current' => true,
    ]);

    return compact('store', 'branch', 'fiscalYear');
}

it('allocates document numbers atomically per branch and type', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear] = createSequenceContext();

    DocumentSequence::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'fiscal_year_id' => $fiscalYear->id,
        'document_type' => DocumentType::JournalEntry,
        'prefix' => 'JE-1405-',
        'next_number' => 1,
        'padding' => 5,
    ]);

    $service = app(DocumentNumberService::class);

    expect($service->next($store->id, $branch->id, $fiscalYear->id, DocumentType::JournalEntry))
        ->toBe('JE-1405-00001')
        ->and($service->next($store->id, $branch->id, $fiscalYear->id, DocumentType::JournalEntry))
        ->toBe('JE-1405-00002')
        ->and(DocumentSequence::query()->first()->next_number)->toBe(3);
});

it('rejects unknown sequence scopes', function () {
    ['store' => $store, 'branch' => $branch, 'fiscalYear' => $fiscalYear] = createSequenceContext();

    app(DocumentNumberService::class)->next(
        $store->id,
        $branch->id,
        $fiscalYear->id,
        DocumentType::SalesInvoice,
    );
})->throws(DomainException::class);
