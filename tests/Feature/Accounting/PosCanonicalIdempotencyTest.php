<?php

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PosSale;
use App\Models\PosTender;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Pos\PosCheckoutService;
use App\Services\Pos\PosCheckResolutionService;
use App\Services\Pos\PosSessionService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;

/** @return array<string, mixed> */
function canonicalPosFoundation(): array
{
    test()->travelTo(now()->setDate(2026, 5, 2)->startOfDay());
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $cash = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'cash')->firstOrFail();
    $card = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'card')->firstOrFail();
    $party = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CANONICAL-POS-CUSTOMER',
        'name' => 'Canonical POS customer',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => '1000000.00',
    ]);
    $user = User::factory()->create(['role' => UserRole::Cashier]);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $product = Product::query()->create([
        'code' => 'CANONICAL-POS-PRODUCT',
        'name' => 'Canonical POS product',
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 10,
    ]);
    app(InventoryService::class)->receive(
        $warehouse->id,
        $product->id,
        10,
        100,
        '2026-05-01',
        'canonical-pos-opening',
        InventoryMovementType::Opening,
    );
    $session = app(PosSessionService::class)->open($branch, $user, $warehouse->id, $cash->id, $card->id, '50');

    return compact('store', 'branch', 'warehouse', 'cash', 'card', 'party', 'customer', 'user', 'manager', 'product', 'session');
}

/** @param array<string, mixed> $context @param array<string, mixed> $overrides */
function canonicalPosPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'session_id' => $context['session']->id,
        'submission_token' => fake()->uuid(),
        'customer_id' => $context['customer']->id,
        'due_date' => null,
        'notes' => 'Canonical POS sale',
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity' => 2,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'tenders' => [[
            'method' => 'cash',
            'amount' => 440,
            'confirmed' => true,
            'reference' => 'POS-REF',
        ]],
    ], $overrides);
}

it('ignores POS client tax while conflicting on every material checkout change', function () {
    $context = canonicalPosFoundation();
    $service = app(PosCheckoutService::class);
    $payload = canonicalPosPayload($context);
    $checkout = fn (array $overrides = []) => $service->checkout(
        $context['session'],
        $context['user'],
        canonicalPosPayload($context, array_replace($payload, $overrides)),
    );
    $first = $checkout();
    $context['product']->update(['is_active' => false]);
    $context['customer']->update(['is_active' => false]);
    $context['party']->update(['is_active' => false]);

    $ignoredTax = $payload;
    $ignoredTax['items'][0]['tax_rate'] = 99;

    expect($service->checkout($context['session'], $context['user'], $ignoredTax)->is($first))->toBeTrue()
        ->and($first->invoice->items()->firstOrFail()->tax_rate)->toBe('10.00')
        ->and(fn () => $checkout(['notes' => 'Changed']))->toThrow(DomainException::class)
        ->and(fn () => $checkout(['items' => [array_replace($payload['items'][0], ['quantity' => 3])]]))->toThrow(DomainException::class)
        ->and(fn () => $checkout(['items' => [array_replace($payload['items'][0], ['unit_price' => 201])]]))->toThrow(DomainException::class)
        ->and(fn () => $checkout(['tenders' => [array_replace($payload['tenders'][0], ['amount' => 430])]]))->toThrow(DomainException::class)
        ->and(fn () => $checkout(['tenders' => [array_replace($payload['tenders'][0], ['reference' => 'CHANGED'])]]))->toThrow(DomainException::class)
        ->and(fn () => $checkout(['tenders' => [array_replace($payload['tenders'][0], ['confirmed' => false])]]))->toThrow(DomainException::class)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->count())->toBe(1);
});

it('compares persisted POS checkout state when the legacy request hash is null', function () {
    $context = canonicalPosFoundation();
    $service = app(PosCheckoutService::class);
    $payload = canonicalPosPayload($context);
    $first = $service->checkout($context['session'], $context['user'], $payload);
    DB::table('pos_sales')->where('id', $first->id)->update(['request_hash' => null]);
    DB::table('invoices')->where('id', $first->invoice_id)->update(['idempotency_request_hash' => null]);
    $context['product']->update(['is_active' => false]);

    $ignoredTax = $payload;
    $ignoredTax['items'][0]['tax_rate'] = 100;

    expect($service->checkout($context['session'], $context['user'], $ignoredTax)->is($first))->toBeTrue()
        ->and(fn () => $service->checkout(
            $context['session'],
            $context['user'],
            array_replace($payload, ['tenders' => [array_replace($payload['tenders'][0], ['reference' => 'CHANGED'])]]),
        ))->toThrow(DomainException::class)
        ->and(PosSale::query()->count())->toBe(1);
});

it('canonicalizes POS close retries and legacy null close hashes', function () {
    $context = canonicalPosFoundation();
    $service = app(PosSessionService::class);
    $close = fn (string $counted, ?string $notes) => $service->close(
        $context['session'],
        $context['user'],
        $counted,
        $notes,
    );
    $first = $close('50', null);

    expect($close('50', null)->is($first))->toBeTrue()
        ->and($first->close_request_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and(fn () => $close('51', 'Difference'))->toThrow(DomainException::class)
        ->and(fn () => $close('50', 'Changed'))->toThrow(DomainException::class);

    DB::table('pos_sessions')->where('id', $first->id)->update(['close_request_hash' => null]);

    expect($close('50', null)->is($first))->toBeTrue()
        ->and(fn () => $close('51', 'Difference'))->toThrow(DomainException::class)
        ->and(fn () => $close('50', 'Changed'))->toThrow(DomainException::class);
});

it('canonicalizes POS check resolution before revalidation and supports legacy null hashes', function () {
    $context = canonicalPosFoundation();
    $payload = canonicalPosPayload($context, [
        'tenders' => [[
            'method' => 'check',
            'amount' => 440,
            'check_received' => false,
            'due_date' => '2026-06-01',
            'notes' => 'Check declaration',
        ]],
    ]);
    $sale = app(PosCheckoutService::class)->checkout($context['session'], $context['user'], $payload);
    $tender = PosTender::query()->where('pos_sale_id', $sale->id)->firstOrFail();
    $service = app(PosCheckResolutionService::class);
    $data = [
        'check_number' => 'CANONICAL-CHECK-1',
        'bank_name' => 'Canonical bank',
        'due_date' => '2026-06-01',
        'receipt_date' => '2026-05-02',
    ];
    $resolve = fn (array $overrides = []) => $service->resolve(
        $tender,
        $context['manager'],
        array_replace($data, $overrides),
    );
    $resolved = $resolve();
    $context['customer']->update(['is_active' => false]);
    $context['party']->update(['is_active' => false]);

    expect($resolve()->is($resolved))->toBeTrue()
        ->and($resolved->resolution_request_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and(fn () => $resolve(['check_number' => 'CANONICAL-CHECK-2']))->toThrow(DomainException::class)
        ->and(fn () => $resolve(['bank_name' => 'Other bank']))->toThrow(DomainException::class)
        ->and(fn () => $resolve(['due_date' => '2026-06-02']))->toThrow(DomainException::class)
        ->and(fn () => $resolve(['receipt_date' => '2026-05-03']))->toThrow(DomainException::class);

    DB::table('pos_tenders')->where('id', $resolved->id)->update(['resolution_request_hash' => null]);

    expect($resolve()->is($resolved))->toBeTrue()
        ->and(fn () => $resolve(['bank_name' => 'Other bank']))->toThrow(DomainException::class)
        ->and(Check::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1);
});
