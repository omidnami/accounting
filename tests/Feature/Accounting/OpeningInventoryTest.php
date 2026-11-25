<?php

use App\Enums\UserRole;
use App\Models\InventoryMovement;
use App\Models\InventoryOpening;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\OpeningInventoryService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\StoreSeeder;

function openingInventoryContext(): array
{
    app(StoreSeeder::class)->run();
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $year = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $product = Product::query()->create([
        'code' => 'OPENING-PRODUCT',
        'name' => 'Opening product',
        'purchase_price' => 100,
        'sale_price' => 200,
        'item_type' => 'stock',
    ]);

    return compact('store', 'branch', 'warehouse', 'year', 'manager', 'product');
}

it('atomically posts opening inventory and a balanced journal with reconciliation', function () {
    $context = openingInventoryContext();
    $service = app(OpeningInventoryService::class);
    $items = [[
        'product_id' => $context['product']->id,
        'quantity' => '2.5',
        'unit_cost' => '100',
    ]];

    $opening = $service->open(
        $context['branch'],
        $context['warehouse'],
        $context['year'],
        $context['year']->starts_on->toDateString(),
        $items,
        $context['manager'],
        'opening-idempotency',
    );

    expect($opening->status)->toBe('posted')
        ->and($opening->total_cost)->toBe('250.00')
        ->and($opening->journalEntry->totalDebit())->toBe('250.00')
        ->and($opening->journalEntry->totalCredit())->toBe('250.00')
        ->and(InventoryMovement::query()->where('source_type', $opening->getMorphClass())->where('source_id', $opening->id)->count())->toBe(1)
        ->and($service->reconciliation(
            $context['store']->id,
            $context['branch']->id,
            $context['year']->starts_on->toDateString(),
        ))->toBe(['gl' => '250.00', 'subledger' => '250.00', 'difference' => '0.00']);

    $retry = $service->open(
        $context['branch'],
        $context['warehouse'],
        $context['year'],
        $context['year']->starts_on->toDateString(),
        $items,
        $context['manager'],
        'opening-idempotency',
    );
    expect($retry->id)->toBe($opening->id)
        ->and(InventoryOpening::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('rejects a changed retry of the same opening idempotency key', function () {
    $context = openingInventoryContext();
    $service = app(OpeningInventoryService::class);
    $service->open(
        $context['branch'],
        $context['warehouse'],
        $context['year'],
        $context['year']->starts_on->toDateString(),
        [[
            'product_id' => $context['product']->id,
            'quantity' => '1',
            'unit_cost' => '100',
        ]],
        $context['manager'],
        'opening-changed-retry',
    );

    expect(fn () => $service->open(
        $context['branch'],
        $context['warehouse'],
        $context['year'],
        $context['year']->starts_on->toDateString(),
        [[
            'product_id' => $context['product']->id,
            'quantity' => '2',
            'unit_cost' => '100',
        ]],
        $context['manager'],
        'opening-changed-retry',
    ))->toThrow(DomainException::class, 'درخواست متفاوت');
});

it('rolls back the entire opening when one item is invalid', function () {
    $context = openingInventoryContext();
    $service = Product::query()->create([
        'code' => 'OPENING-SERVICE',
        'name' => 'Service',
        'item_type' => 'service',
    ]);

    expect(fn () => app(OpeningInventoryService::class)->open(
        $context['branch'],
        $context['warehouse'],
        $context['year'],
        $context['year']->starts_on->toDateString(),
        [
            [
                'product_id' => $context['product']->id,
                'quantity' => '1',
                'unit_cost' => '100',
            ],
            [
                'product_id' => $service->id,
                'quantity' => '1',
                'unit_cost' => '100',
            ],
        ],
        $context['manager'],
        'opening-rollback',
    ))->toThrow(DomainException::class);

    expect(InventoryOpening::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});
