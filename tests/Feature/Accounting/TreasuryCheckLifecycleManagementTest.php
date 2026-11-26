<?php

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Check;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Audit\AuditLogService;
use App\Services\Treasury\CheckLifecycleService;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function b23Context(): array
{
    app(StoreSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $fiscalYear = app(FiscalYearSetupService::class)->setupJalaliYear($store, 1405);
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'B23-CUSTOMER',
        'name' => 'مشتری B23',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 1_000_000,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'B23-SUPPLIER',
        'name' => 'تأمین‌کننده B23',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
    ]);
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();

    return compact('store', 'branch', 'fiscalYear', 'customerParty', 'customer', 'supplierParty', 'supplier', 'manager');
}

function b23Check(array $context, CheckType $type = CheckType::Receivable, array $overrides = []): Check
{
    return Check::query()->create(array_replace([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'document_number' => 'CK-B23-'.fake()->uuid(),
        'check_number' => 'B23-'.fake()->unique()->numerify('########'),
        'bank_name' => 'بانک B23',
        'branch_name' => 'شعبه بانک B23',
        'amount' => '100.00',
        'issue_date' => '2026-05-01',
        'due_date' => '2026-05-10',
        'description' => 'توضیح اولیه',
        'type' => $type,
        'status' => CheckStatus::Pending,
        'customer_id' => $type === CheckType::Receivable ? $context['customer']->id : null,
        'supplier_id' => $type === CheckType::Payable ? $context['supplier']->id : null,
        'created_by' => $context['manager']->id,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function b23UpdatePayload(Check $check, array $overrides = []): array
{
    return array_replace([
        'check_number' => $check->check_number,
        'bank_name' => $check->bank_name,
        'branch_name' => $check->branch_name,
        'amount' => $check->amount,
        'issue_date' => $check->issue_date?->toDateString(),
        'due_date' => $check->due_date->toDateString(),
        'description' => $check->description,
    ], $overrides);
}

function b23Receipt(array $context, Check $check): Receipt
{
    return Receipt::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'receipt_number' => 'RC-B23-'.fake()->uuid(),
        'receipt_date' => '2026-05-02',
        'amount' => $check->amount,
        'payment_method' => PaymentMethod::Check,
        'customer_id' => $check->customer_id,
        'check_id' => $check->id,
        'created_by' => $context['manager']->id,
    ]);
}

function b23Payment(array $context, Check $check): Payment
{
    return Payment::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'payment_number' => 'PY-B23-'.fake()->uuid(),
        'payment_date' => '2026-05-02',
        'amount' => $check->amount,
        'payment_method' => PaymentMethod::Check,
        'supplier_id' => $check->supplier_id,
        'check_id' => $check->id,
        'created_by' => $context['manager']->id,
    ]);
}

it('authorizes check edit update and cancel by canManageChecks', function () {
    $context = b23Context();
    $check = b23Check($context);
    $cashier = User::factory()->create();
    $cashier->forceFill(['role' => UserRole::Cashier])->save();
    $payload = b23UpdatePayload($check, ['amount' => '125.50']);

    $this->actingAs($cashier)
        ->get(route('checks.edit', $check))
        ->assertForbidden();
    $this->actingAs($cashier)
        ->patch(route('checks.update', $check), $payload)
        ->assertForbidden();
    $this->actingAs($cashier)
        ->post(route('checks.cancel', $check), ['reason' => 'ابطال آزمایشی'])
        ->assertForbidden();

    expect(fn () => app(CheckLifecycleService::class)->update($check, $payload, $cashier))
        ->toThrow(DomainException::class)
        ->and(fn () => app(CheckLifecycleService::class)->cancel($check, 'ابطال آزمایشی', $cashier))
        ->toThrow(DomainException::class)
        ->and($check->fresh()->amount)->toBe('100.00')
        ->and($check->fresh()->status)->toBe(CheckStatus::Pending);
});

it('rejects check lifecycle actions outside the active store and branch', function () {
    $context = b23Context();
    $otherBranch = $context['store']->branches()->create([
        'code' => 'B23-OTHER-BRANCH',
        'name' => 'شعبه دیگر B23',
    ]);
    $otherStore = Store::query()->create([
        'code' => 'B23-OTHER-STORE',
        'name' => 'فروشگاه دیگر B23',
    ]);
    $otherStoreBranch = $otherStore->branches()->create([
        'code' => 'MAIN',
        'name' => 'شعبه اصلی دیگر',
        'is_default' => true,
    ]);
    $otherParty = Party::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'B23-OTHER-CUSTOMER',
        'name' => 'مشتری فروشگاه دیگر',
    ]);
    $otherCustomer = Customer::query()->create(['party_id' => $otherParty->id]);
    $checks = [
        b23Check($context, overrides: ['branch_id' => $otherBranch->id]),
        b23Check($context, overrides: [
            'store_id' => $otherStore->id,
            'branch_id' => $otherStoreBranch->id,
            'customer_id' => $otherCustomer->id,
        ]),
    ];

    $this->actingAs($context['manager']);

    foreach ($checks as $check) {
        $this->get(route('checks.edit', $check))->assertNotFound();
        $this->patch(route('checks.update', $check), b23UpdatePayload($check))
            ->assertNotFound();
        $this->post(route('checks.cancel', $check), ['reason' => 'ابطال خارج از scope'])
            ->assertNotFound();

        expect($check->fresh()->status)->toBe(CheckStatus::Pending)
            ->and($check->fresh()->check_number)->toBe($check->check_number);
    }
});

it('edits a pending unlinked check and atomically audits old and new values', function () {
    $this->withoutVite();
    $context = b23Context();
    $check = b23Check($context);
    $identity = [
        'type' => $check->type,
        'store_id' => $check->store_id,
        'branch_id' => $check->branch_id,
        'document_number' => $check->document_number,
        'customer_id' => $check->customer_id,
        'supplier_id' => $check->supplier_id,
    ];

    $this->actingAs($context['manager'])
        ->get(route('checks.edit', $check))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checks/Edit')
            ->where('check.id', $check->id)
            ->where('check.can_edit', true)
            ->where('check.amount', '100.00'));

    $this->patch(route('checks.update', $check), array_replace(b23UpdatePayload($check, [
        'check_number' => 'B23-EDITED',
        'bank_name' => 'بانک ویرایش‌شده',
        'branch_name' => null,
        'amount' => '125.50',
        'issue_date' => null,
        'due_date' => '2026-06-10',
        'description' => 'توضیح ویرایش‌شده',
    ]), [
        'type' => CheckType::Payable->value,
        'store_id' => 999999,
        'branch_id' => 999999,
        'document_number' => 'CK-HACKED',
        'customer_id' => 999999,
        'supplier_id' => 999999,
    ]))
        ->assertRedirect(route('checks.show', $check))
        ->assertSessionHasNoErrors();

    $fresh = $check->fresh();
    $audit = AuditLog::query()
        ->where('event', 'check_updated')
        ->where('auditable_type', $check->getMorphClass())
        ->where('auditable_id', $check->id)
        ->firstOrFail();

    expect($fresh->check_number)->toBe('B23-EDITED')
        ->and($fresh->bank_name)->toBe('بانک ویرایش‌شده')
        ->and($fresh->branch_name)->toBeNull()
        ->and($fresh->amount)->toBe('125.50')
        ->and($fresh->issue_date)->toBeNull()
        ->and($fresh->due_date->toDateString())->toBe('2026-06-10')
        ->and($fresh->description)->toBe('توضیح ویرایش‌شده')
        ->and($fresh->type)->toBe($identity['type'])
        ->and($fresh->store_id)->toBe($identity['store_id'])
        ->and($fresh->branch_id)->toBe($identity['branch_id'])
        ->and($fresh->document_number)->toBe($identity['document_number'])
        ->and($fresh->customer_id)->toBe($identity['customer_id'])
        ->and($fresh->supplier_id)->toBe($identity['supplier_id'])
        ->and($audit->old_values['check_number'])->toBe($check->check_number)
        ->and($audit->old_values['amount'])->toBe('100.00')
        ->and($audit->new_values['check_number'])->toBe('B23-EDITED')
        ->and($audit->new_values['amount'])->toBe('125.50')
        ->and($audit->user_id)->toBe($context['manager']->id);
});

it('keeps immutable identity fields unchanged when the lifecycle service receives them', function () {
    $context = b23Context();
    $check = b23Check($context);
    $this->actingAs($context['manager']);

    $updated = app(CheckLifecycleService::class)->update($check, array_replace(
        b23UpdatePayload($check, ['amount' => '150']),
        [
            'type' => CheckType::Payable->value,
            'store_id' => 999999,
            'branch_id' => 999999,
            'document_number' => 'CK-HACKED',
            'customer_id' => 999999,
            'supplier_id' => $context['supplier']->id,
        ],
    ), $context['manager']);

    expect($updated->type)->toBe(CheckType::Receivable)
        ->and($updated->store_id)->toBe($context['store']->id)
        ->and($updated->branch_id)->toBe($context['branch']->id)
        ->and($updated->document_number)->toBe($check->document_number)
        ->and($updated->customer_id)->toBe($context['customer']->id)
        ->and($updated->supplier_id)->toBeNull()
        ->and($updated->amount)->toBe('150.00');
});

it('cancels a pending unlinked check with a reason and shows it in the index', function () {
    $context = b23Context();
    $check = b23Check($context);
    $this->actingAs($context['manager']);

    $this->post(route('checks.cancel', $check), ['reason' => '  ابطال به درخواست مشتری  '])
        ->assertRedirect(route('checks.show', $check))
        ->assertSessionHasNoErrors();

    $fresh = $check->fresh();
    $audit = AuditLog::query()
        ->where('event', 'check_cancelled')
        ->where('auditable_type', $check->getMorphClass())
        ->where('auditable_id', $check->id)
        ->firstOrFail();

    expect($fresh->status)->toBe(CheckStatus::Cancelled)
        ->and($fresh->description)->toBe("توضیح اولیه\nابطال: ابطال به درخواست مشتری")
        ->and($audit->old_values['status'])->toBe('pending')
        ->and($audit->old_values['description'])->toBe('توضیح اولیه')
        ->and($audit->new_values['status'])->toBe('cancelled')
        ->and($audit->new_values['reason'])->toBe('ابطال به درخواست مشتری')
        ->and($audit->user_id)->toBe($context['manager']->id);

    $this->get(route('checks.index', ['status' => 'cancelled']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checks/Index')
            ->has('checks.data', 1)
            ->where('checks.data.0.id', $check->id)
            ->where('checks.data.0.status', 'cancelled')
            ->where('checks.data.0.can_edit', false));

    $this->get(route('checks.show', $check))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('check.status', 'cancelled')
            ->where('check.can_edit', false)
            ->where('check.can_cancel', false));
});

it('rejects editing and cancelling a check linked to any pending treasury document', function (string $kind) {
    $context = b23Context();
    $type = $kind === 'receipt' ? CheckType::Receivable : CheckType::Payable;
    $check = b23Check($context, $type);
    $document = $kind === 'receipt'
        ? b23Receipt($context, $check)
        : b23Payment($context, $check);
    $this->actingAs($context['manager']);

    expect($document->status->value)->toBe('pending')
        ->and(fn () => app(CheckLifecycleService::class)->update(
            $check,
            b23UpdatePayload($check, ['amount' => '200']),
            $context['manager'],
        ))->toThrow(DomainException::class)
        ->and(fn () => app(CheckLifecycleService::class)->cancel(
            $check,
            'ابطال چک متصل',
            $context['manager'],
        ))->toThrow(DomainException::class);

    $this->get(route('checks.edit', $check))->assertNotFound();
    $this->patch(route('checks.update', $check), b23UpdatePayload($check, ['amount' => '200']))
        ->assertSessionHasErrors('check');
    $this->post(route('checks.cancel', $check), ['reason' => 'ابطال چک متصل'])
        ->assertSessionHasErrors('check');
    $this->get(route('checks.show', $check))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('check.status', 'pending')
            ->where('check.is_linked', true)
            ->where('check.can_edit', false)
            ->where('check.can_cancel', false)
            ->where('check.treasury_document.kind', $kind)
            ->where('check.treasury_document.status', 'pending'));

    expect($check->fresh()->status)->toBe(CheckStatus::Pending)
        ->and($check->fresh()->amount)->toBe('100.00');
})->with(['receipt', 'payment']);

it('rejects editing and cancelling settled bounced or cancelled checks', function (CheckStatus $status) {
    $context = b23Context();
    $check = b23Check($context);
    DB::table('checks')->where('id', $check->id)->update(['status' => $status->value]);
    $this->actingAs($context['manager']);

    expect(fn () => app(CheckLifecycleService::class)->update(
        $check,
        b23UpdatePayload($check, ['amount' => '200']),
        $context['manager'],
    ))->toThrow(DomainException::class)
        ->and(fn () => app(CheckLifecycleService::class)->cancel(
            $check,
            'ابطال چک نهایی‌شده',
            $context['manager'],
        ))->toThrow(DomainException::class);

    $this->get(route('checks.edit', $check))->assertNotFound();
    $this->patch(route('checks.update', $check), b23UpdatePayload($check, ['amount' => '200']))
        ->assertSessionHasErrors('check');
    $this->post(route('checks.cancel', $check), ['reason' => 'ابطال چک نهایی‌شده'])
        ->assertSessionHasErrors('check');

    expect($check->fresh()->status)->toBe($status)
        ->and($check->fresh()->amount)->toBe('100.00')
        ->and(AuditLog::query()
            ->whereIn('event', ['check_updated', 'check_cancelled'])
            ->where('auditable_id', $check->id)
            ->count())->toBe(0);
})->with([
    'collected' => [CheckStatus::Collected],
    'spent' => [CheckStatus::Spent],
    'bounced' => [CheckStatus::Bounced],
    'cancelled' => [CheckStatus::Cancelled],
]);

it('rolls back check changes when lifecycle audit recording fails', function (string $operation) {
    $context = b23Context();
    $check = b23Check($context);
    $this->actingAs($context['manager']);
    $transactionLevel = DB::transactionLevel();
    $event = $operation === 'update' ? 'check_updated' : 'check_cancelled';
    $audit = Mockery::mock(AuditLogService::class)->makePartial();
    $audit->shouldReceive('record')
        ->withArgs(fn (string $receivedEvent): bool => $receivedEvent === $event)
        ->once()
        ->andReturnUsing(function () use ($transactionLevel): void {
            expect(DB::transactionLevel())->toBe($transactionLevel + 1);
            throw new RuntimeException('audit-failed');
        });
    $audit->shouldReceive('record')
        ->withArgs(fn (string $receivedEvent): bool => $receivedEvent === 'updated')
        ->andReturnNull();
    app()->instance(AuditLogService::class, $audit);

    expect(fn () => $operation === 'update'
        ? app(CheckLifecycleService::class)->update(
            $check,
            b23UpdatePayload($check, ['amount' => '200']),
            $context['manager'],
        )
        : app(CheckLifecycleService::class)->cancel($check, 'ابطال ناموفق', $context['manager']))
        ->toThrow(RuntimeException::class, 'audit-failed');

    expect($check->fresh()->amount)->toBe('100.00')
        ->and($check->fresh()->status)->toBe(CheckStatus::Pending)
        ->and($check->fresh()->description)->toBe('توضیح اولیه')
        ->and(AuditLog::query()->where('event', $event)->where('auditable_id', $check->id)->count())->toBe(0);
})->with(['update', 'cancel']);

it('rejects an update that collides with another external check identity', function () {
    $context = b23Context();
    $check = b23Check($context);
    $other = b23Check($context, overrides: [
        'bank_name' => 'بانک مشترک',
        'check_number' => 'SHARED-B23',
    ]);
    $check->forceFill([
        'bank_name' => 'بانک اولیه',
        'check_number' => 'SOURCE-B23',
    ])->save();
    $this->actingAs($context['manager']);
    $payload = b23UpdatePayload($check, [
        'bank_name' => $other->bank_name,
        'check_number' => $other->check_number,
    ]);

    expect(fn () => app(CheckLifecycleService::class)->update($check, $payload, $context['manager']))
        ->toThrow(DomainException::class, 'این چک قبلاً با همان بانک و شماره ثبت شده است.');
    $this->patch(route('checks.update', $check), $payload)
        ->assertSessionHasErrors('check');

    expect($check->fresh()->bank_name)->toBe('بانک اولیه')
        ->and($check->fresh()->check_number)->toBe('SOURCE-B23')
        ->and(AuditLog::query()->where('event', 'check_updated')->where('auditable_id', $check->id)->count())->toBe(0);
});

it('returns real party profile ids and check id for edit and treasury preselection', function (CheckType $type) {
    $context = b23Context();
    $check = b23Check($context, $type);
    $this->actingAs($context['manager']);

    $this->get(route('checks.show', $check))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checks/Show')
            ->where('check.check_id', $check->id)
            ->where('check.customer_id', $type === CheckType::Receivable ? $context['customer']->id : null)
            ->where('check.supplier_id', $type === CheckType::Payable ? $context['supplier']->id : null)
            ->where('check.party.id', $type === CheckType::Receivable ? $context['customerParty']->id : $context['supplierParty']->id)
            ->where('check.can_edit', true)
            ->where('check.can_cancel', true)
            ->where('check.is_linked', false));

    if ($type === CheckType::Receivable) {
        $this->get(route('receipts.create', [
            'customer_id' => $context['customer']->id,
            'check_id' => $check->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('initial_check_id', $check->id)
            ->has('initial_checks', 1));
    } else {
        $this->get(route('payments.create', [
            'supplier_id' => $context['supplier']->id,
            'check_id' => $check->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('initial_check_id', $check->id)
            ->has('initial_checks', 1));
    }
})->with([
    'receivable' => [CheckType::Receivable],
    'payable' => [CheckType::Payable],
]);
