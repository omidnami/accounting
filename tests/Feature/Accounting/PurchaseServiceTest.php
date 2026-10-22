<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Purchasing\PurchaseService;
use Database\Seeders\AccountSeeder;
use Illuminate\Auth\Access\AuthorizationException;

function createPurchaseContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_default', true)->firstOrFail();
    $fiscalYear = app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $party = Party::query()->create([
        'code' => 'SUP-PARTY-001',
        'name' => 'تأمین‌کننده آزمایشی',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
        'payment_terms' => '30 روز',
    ]);

    return compact('store', 'branch', 'warehouse', 'fiscalYear', 'party', 'supplier');
}

function purchaseUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('confirms a purchase invoice into inventory and accounting atomically', function () {
    $context = createPurchaseContext();
    $manager = purchaseUser(UserRole::Manager);
    $product = Product::query()->create([
        'code' => 'PURCHASE-P-001',
        'name' => 'کالای خرید',
        'unit' => 'عدد',
        'purchase_price' => 100_000,
        'sale_price' => 150_000,
        'tax_rate' => 10,
    ]);
    $service = app(PurchaseService::class);

    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
            'due_date' => '2026-05-31',
            'created_by' => $manager->id,
        ],
        [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_price' => 100_000,
                'tax_rate' => 10,
            ],
        ],
    );

    expect($invoice->type)->toBe(InvoiceType::Purchase)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->subtotal)->toBe('1000000.00')
        ->and($invoice->tax_total)->toBe('100000.00')
        ->and($invoice->total)->toBe('1100000.00');

    $confirmed = $service->confirm($invoice, $context['warehouse']->id, $manager);
    $balance = InventoryBalance::query()
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('product_id', $product->id)
        ->firstOrFail();
    $movement = InventoryMovement::query()->firstOrFail();
    $journal = JournalEntry::query()->with('lines.account')->firstOrFail();
    $payableLine = $journal->lines->first(
        fn ($line): bool => $line->account->code === '2101',
    );

    expect($confirmed->status)->toBe(InvoiceStatus::Confirmed)
        ->and($balance->quantity)->toBe('10.000')
        ->and($balance->inventory_value)->toBe('1000000.00')
        ->and($movement->movement_type)->toBe(InventoryMovementType::Purchase)
        ->and($movement->source_type)->toBe(Invoice::class)
        ->and($movement->source_id)->toBe($confirmed->id)
        ->and($journal->status->value)->toBe('posted')
        ->and($journal->source_type)->toBe(Invoice::class)
        ->and($journal->source_id)->toBe($confirmed->id)
        ->and($journal->totalDebit())->toBe('1100000.00')
        ->and($journal->totalCredit())->toBe('1100000.00')
        ->and($payableLine?->party_id)->toBe($context['party']->id)
        ->and(Account::query()->where('code', '2101')->first()->requires_party)->toBeTrue();
});

it('rolls back inventory when an unauthorized user confirms a purchase', function () {
    $context = createPurchaseContext();
    $cashier = purchaseUser(UserRole::Cashier);
    $product = Product::query()->create([
        'code' => 'PURCHASE-P-002',
        'name' => 'کالای خرید دوم',
        'purchase_price' => 50_000,
        'sale_price' => 80_000,
    ]);
    $service = app(PurchaseService::class);
    $invoice = $service->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => '2026-05-01',
            'created_by' => $cashier->id,
        ],
        [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 50_000,
            ],
        ],
    );

    expect(fn () => $service->confirm($invoice, $context['warehouse']->id, $cashier))
        ->toThrow(AuthorizationException::class);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});
