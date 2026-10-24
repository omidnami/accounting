<?php

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosTender;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Pos\PosPostingContext;
use App\Services\Pos\PosSessionService;
use App\Services\Sales\CustomerReceivableService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;

function posContext(): array
{
    test()->travelTo(now()->setDate(2026, 5, 2)->startOfDay());
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $cash = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'cash')->firstOrFail();
    $card = TreasuryAccount::query()->where('branch_id', $branch->id)->where('type', 'card')->firstOrFail();
    $party = Party::query()->create(['store_id' => $store->id, 'code' => 'POS-TEST', 'name' => 'مشتری صندوق']);
    $customer = Customer::query()->create(['party_id' => $party->id, 'credit_limit' => '1000000']);
    $user = User::factory()->create(['role' => UserRole::Cashier]);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $product = Product::query()->create(['code' => 'POS-01', 'name' => 'کالای صندوق', 'unit' => 'عدد', 'purchase_price' => 100, 'sale_price' => 200, 'tax_rate' => 10]);
    app(InventoryService::class)->receive($warehouse->id, $product->id, 10, 100, '2026-05-01', 'pos-opening', InventoryMovementType::Opening);
    $session = app(PosSessionService::class)->open($branch, $user, $warehouse->id, $cash->id, $card->id, '50');

    return compact('store', 'branch', 'warehouse', 'cash', 'card', 'customer', 'user', 'manager', 'product', 'session');
}

function posPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'session_id' => $context['session']->id,
        'submission_token' => fake()->uuid(),
        'customer_id' => $context['customer']->id,
        'due_date' => null,
        'notes' => 'فروش آزمایشی صندوق',
        'items' => [['product_id' => $context['product']->id, 'quantity' => '2', 'unit_price' => '200', 'discount_amount' => '0', 'tax_rate' => '10']],
        'tenders' => [['method' => 'cash', 'amount' => '440', 'confirmed' => true]],
    ], $overrides);
}

it('opens the dedicated POS for sales roles and protects it from other roles', function () {
    $context = posContext();
    $this->actingAs($context['user'])->get(route('pos.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('pos/Index')->where('session.id', $context['session']->id)->where('can_review', false));
    $this->actingAs(User::factory()->create(['role' => UserRole::Warehouse]))->get(route('pos.index'))->assertForbidden();
});

it('posts a cashier sale and payment once without granting manual journal rights', function () {
    $context = posContext();
    $payload = posPayload($context);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('sale.status', 'paid');
    $this->postJson(route('pos.checkout'), $payload)->assertOk();
    expect(Invoice::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(InventoryBalance::query()->where('product_id', $context['product']->id)->value('quantity'))->toBe('8.000')
        ->and($context['user']->canApproveJournalEntries())->toBeFalse();
    foreach (JournalEntry::query()->get() as $entry) {
        expect($entry->totalDebit())->toBe($entry->totalCredit())->and($entry->created_by)->toBe($context['user']->id);
    }
    $this->postJson(route('sales.confirm', Invoice::query()->first()), ['warehouse_id' => $context['warehouse']->id])->assertForbidden();
    $this->get(route('receipts.create'))->assertForbidden();
});

it('keeps check declarations and credit outstanding until the accountant registers the check', function () {
    $context = posContext();
    $payload = posPayload($context, ['due_date' => '2026-06-01', 'tenders' => [
        ['method' => 'cash', 'amount' => '100', 'confirmed' => true],
        ['method' => 'card', 'amount' => '100', 'confirmed' => true, 'reference' => '123'],
        ['method' => 'check', 'amount' => '200', 'check_received' => false, 'notes' => 'فردا تحویل می‌دهد'],
        ['method' => 'credit', 'amount' => '40'],
    ]]);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('sale.outstanding', '240.00');
    expect(Check::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(2);
    $tender = PosTender::query()->where('method', 'check')->firstOrFail();
    $registration = ['check_number' => '123456', 'bank_name' => 'ملت', 'due_date' => '2026-07-01', 'receipt_date' => '2026-05-02'];
    $this->postJson(route('pos.checks.register', $tender), $registration)->assertForbidden();
    $this->actingAs($context['manager'])->postJson(route('pos.checks.register', $tender), $registration)->assertOk();
    $this->postJson(route('pos.checks.register', $tender), $registration)->assertOk();
    expect(Check::query()->count())->toBe(1)->and(Receipt::query()->count())->toBe(3)
        ->and(app(CustomerReceivableService::class)->outstandingForInvoice(Invoice::query()->first()))->toBe('40.00')
        ->and($tender->fresh()->check_received)->toBeTrue();
});

it('allows anonymous fully paid sales with zero credit and rejects anonymous credit or checks', function () {
    $context = posContext();
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context, ['customer_id' => null]))
        ->assertOk()->assertJsonPath('sale.status', 'paid');
    $this->postJson(route('pos.checkout'), posPayload($context, ['customer_id' => null, 'due_date' => '2026-06-01', 'tenders' => [['method' => 'credit', 'amount' => '440']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('pos');
    expect(PosSale::query()->count())->toBe(1);
});

it('checks credit only on the unpaid portion but still enforces its limit', function () {
    $context = posContext();
    $context['customer']->update(['credit_limit' => '40']);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context, ['due_date' => '2026-06-01', 'tenders' => [
        ['method' => 'cash', 'amount' => '400', 'confirmed' => true], ['method' => 'credit', 'amount' => '40'],
    ]]))->assertOk();
    $this->postJson(route('pos.checkout'), posPayload($context, ['due_date' => '2026-06-01', 'tenders' => [['method' => 'credit', 'amount' => '440']]]))->assertUnprocessable();
    expect(PosSale::query()->count())->toBe(1);
    $context['customer']->update(['credit_limit' => '0']);
    $this->postJson(route('pos.checkout'), posPayload($context))->assertOk();
});

it('rolls back invoice inventory and journals if a card account is missing', function () {
    $context = posContext();
    $context['session']->update(['card_account_id' => null]);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context, ['tenders' => [['method' => 'card', 'amount' => '440', 'confirmed' => true]]]))->assertUnprocessable();
    expect(Invoice::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0)
        ->and(PosSale::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(0)
        ->and(InventoryBalance::query()->where('product_id', $context['product']->id)->value('quantity'))->toBe('10.000');
});

it('rejects changed retries, incorrect splits and unconfirmed receipts', function () {
    $context = posContext();
    $payload = posPayload($context);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk();
    $this->postJson(route('pos.checkout'), [...$payload, 'notes' => 'تغییر'])->assertUnprocessable();
    $this->postJson(route('pos.checkout'), posPayload($context, ['tenders' => [['method' => 'cash', 'amount' => '430', 'confirmed' => true]]]))->assertUnprocessable();
    $this->postJson(route('pos.checkout'), posPayload($context, ['tenders' => [['method' => 'card', 'amount' => '440', 'confirmed' => false]]]))->assertUnprocessable();
    expect(Invoice::query()->count())->toBe(1)->and(Receipt::query()->count())->toBe(1);
});

it('prevents overselling and prevents using someone elses shift', function () {
    $context = posContext();
    $payload = posPayload($context);
    $payload['items'][0]['quantity'] = '11';
    $payload['tenders'][0]['amount'] = '2420';
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertUnprocessable();
    $this->actingAs($context['manager'])->postJson(route('pos.checkout'), posPayload($context))->assertForbidden();
    expect(Invoice::query()->count())->toBe(0);
});

it('closes a shift without reposting sales and requires an explanation for a cash difference', function () {
    $context = posContext();
    $payload = posPayload($context);
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), $payload)->assertOk();
    $this->postJson(route('pos.close', $context['session']), ['counted_cash' => '480'])->assertUnprocessable();
    $this->postJson(route('pos.close', $context['session']), ['counted_cash' => '480', 'notes' => 'ده ریال کسری'])->assertOk();
    expect($context['session']->fresh()->expected_cash)->toBe('490.00')
        ->and($context['session']->fresh()->cash_difference)->toBe('-10.00')
        ->and(PosSession::query()->whereNotNull('active_user_id')->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(2);
    // A lost response can safely be recovered even after closing the shift.
    $this->postJson(route('pos.checkout'), $payload)->assertOk();
    $this->postJson(route('pos.checkout'), posPayload($context))->assertUnprocessable();
});

it('prevents a second active shift on an occupied cash drawer', function () {
    $context = posContext();
    $this->actingAs($context['manager'])->postJson(route('pos.open'), [
        'warehouse_id' => $context['warehouse']->id, 'cash_account_id' => $context['cash']->id, 'opening_cash' => '0',
    ])->assertUnprocessable();
    expect(PosSession::query()->count())->toBe(1);
});

it('does not let POS authorization post arbitrary or already completed source documents', function () {
    $context = posContext();
    $this->actingAs($context['user'])->postJson(route('pos.checkout'), posPayload($context))->assertOk();
    $sale = PosSale::query()->firstOrFail();
    $entry = JournalEntry::query()->firstOrFail();
    expect((new PosPostingContext($sale->id))->allows($entry, $context['user']))->toBeFalse();
    expect(fn () => app(PostingService::class)->approveAndPost($entry, $context['user'], new PosPostingContext($sale->id)))
        ->toThrow(AuthorizationException::class);
});
