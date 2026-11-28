<?php

use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Store;
use App\Models\User;
use App\Services\Parties\PartySnapshotService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function salesSnapshotBackfillContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    return compact('store', 'branch', 'fiscalYear', 'manager');
}

/**
 * @param  array<string, mixed>  $context
 */
function createLegacySnapshotInvoice(array $context, Customer $customer, string $invoiceNumber): Invoice
{
    return Invoice::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['fiscalYear']->id,
        'invoice_number' => $invoiceNumber,
        'type' => InvoiceType::Sales,
        'invoice_date' => '2026-05-10',
        'customer_id' => $customer->id,
        'created_by' => $context['manager']->id,
    ]);
}

it('dry-runs by default and backfills from ordered audit evidence idempotently', function () {
    $context = salesSnapshotBackfillContext();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'BACKFILL-CUSTOMER',
        'entity_type' => 'organization',
        'name' => 'نام اولیه مشتری',
        'phone' => '02100000000',
        'email' => 'backfill@example.test',
        'national_id' => '1000000001',
        'tax_id' => '2000000001',
        'address' => 'آدرس اولیه مشتری',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'credit_limit' => '1000000',
        'is_active' => true,
    ]);
    $party->refresh();
    $createdAuditId = (int) AuditLog::query()
        ->where('auditable_type', Party::class)
        ->where('auditable_id', $party->id)
        ->where('event', 'created')
        ->value('id');

    $this->travelTo(CarbonImmutable::parse('2026-05-02 09:00:00'));
    $party->update(['name' => 'نام زمان فاکتور', 'version' => 1]);
    $party->update(['phone' => '02111111111', 'version' => 2]);
    $updatedAuditIds = AuditLog::query()
        ->where('auditable_type', Party::class)
        ->where('auditable_id', $party->id)
        ->where('event', 'updated')
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();

    $this->travelTo(CarbonImmutable::parse('2026-05-03 09:00:00'));
    $invoice = createLegacySnapshotInvoice($context, $customer, 'LEGACY-SNAPSHOT-001');
    $invoiceCreatedAt = DB::table('invoices')->where('id', $invoice->id)->value('created_at');

    $this->travelTo(CarbonImmutable::parse('2026-05-04 09:00:00'));
    $party->update(['name' => 'نام فعلی قابل جعل نیست', 'version' => 3]);

    $this->artisan('sales:backfill-party-snapshots')
        ->expectsOutputToContain('[would_backfill] LEGACY-SNAPSHOT-001')
        ->assertSuccessful();

    expect(DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot'))->toBeNull()
        ->and(AuditLog::query()->where('event', 'sales_snapshot_backfilled')->count())->toBe(0);

    $this->artisan('sales:backfill-party-snapshots', ['--apply' => true])
        ->expectsOutputToContain('[backfilled] LEGACY-SNAPSHOT-001')
        ->assertSuccessful();

    $rawSnapshot = DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot');
    $snapshot = json_decode($rawSnapshot, true, 512, JSON_THROW_ON_ERROR);

    expect($snapshot['name'])->toBe('نام زمان فاکتور')
        ->and($snapshot['phone'])->toBe('02111111111')
        ->and($snapshot['party_version'])->toBe(2)
        ->and($snapshot)->not->toHaveKey('legacy_current')
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('created_at'))->toBe($invoiceCreatedAt);

    $audit = AuditLog::query()
        ->where('event', 'sales_snapshot_backfilled')
        ->where('auditable_type', Invoice::class)
        ->where('auditable_id', $invoice->id)
        ->firstOrFail();

    expect($audit->old_values)->toBe(['party_snapshot' => null])
        ->and($audit->new_values['party_snapshot'])->toBe($snapshot)
        ->and($audit->new_values['evidence_audit_ids'])->toBe([$createdAuditId, ...$updatedAuditIds])
        ->and($audit->new_values['invoice_created_at'])->toBe($invoiceCreatedAt)
        ->and($audit->new_values['time_policy'])->toBe('invoices.created_at')
        ->and(AuditLog::query()
            ->where('auditable_type', Invoice::class)
            ->where('auditable_id', $invoice->id)
            ->where('event', 'updated')
            ->count())->toBe(0);

    $this->artisan('sales:backfill-party-snapshots', ['--apply' => true])
        ->expectsOutputToContain('backfilled: 0')
        ->assertSuccessful();

    expect(DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot'))->toBe($rawSnapshot)
        ->and(AuditLog::query()->where('event', 'sales_snapshot_backfilled')->count())->toBe(1);
});

it('backfills sparse legacy audits only for immutable or provably null fields', function () {
    $context = salesSnapshotBackfillContext();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    $party = Party::withoutEvents(fn (): Party => Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SPARSE-AUDIT-CUSTOMER',
        'name' => 'نام تاریخی مشتری',
        'phone' => null,
    ]));
    AuditLog::query()->create([
        'store_id' => $context['store']->id,
        'user_id' => $context['manager']->id,
        'event' => 'created',
        'description' => 'sparse legacy Party audit',
        'auditable_type' => Party::class,
        'auditable_id' => $party->id,
        'new_values' => [
            'id' => $party->id,
            'store_id' => $party->store_id,
            'code' => $party->code,
            'name' => $party->name,
        ],
    ]);
    $customer = Customer::query()->create(['party_id' => $party->id, 'is_active' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-05-02 09:00:00'));
    $invoice = createLegacySnapshotInvoice($context, $customer, 'LEGACY-SPARSE-AUDIT');

    $this->travelTo(CarbonImmutable::parse('2026-05-03 09:00:00'));
    DB::table('parties')->where('id', $party->id)->update(['name' => 'نام فعلی', 'version' => 1]);

    $this->artisan('sales:backfill-party-snapshots', ['--apply' => true])
        ->expectsOutputToContain('[backfilled] LEGACY-SPARSE-AUDIT')
        ->assertSuccessful();
    $snapshot = json_decode(
        (string) DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($snapshot['name'])->toBe('نام تاریخی مشتری')
        ->and($snapshot['entity_type'])->toBe('individual')
        ->and($snapshot['party_version'])->toBe(0)
        ->and($snapshot['phone'])->toBeNull();
});

it('does not invent missing historical contact values from the current party', function () {
    $context = salesSnapshotBackfillContext();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    $party = Party::withoutEvents(fn (): Party => Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'SPARSE-CONTACT-CUSTOMER',
        'name' => 'نام تاریخی',
    ]));
    AuditLog::query()->create([
        'store_id' => $context['store']->id,
        'user_id' => $context['manager']->id,
        'event' => 'created',
        'description' => 'sparse legacy Party audit',
        'auditable_type' => Party::class,
        'auditable_id' => $party->id,
        'new_values' => [
            'id' => $party->id,
            'store_id' => $party->store_id,
            'code' => $party->code,
            'name' => $party->name,
        ],
    ]);
    $customer = Customer::query()->create(['party_id' => $party->id, 'is_active' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-05-02 09:00:00'));
    $invoice = createLegacySnapshotInvoice($context, $customer, 'LEGACY-SPARSE-CONTACT');

    $this->travelTo(CarbonImmutable::parse('2026-05-03 09:00:00'));
    DB::table('parties')->where('id', $party->id)->update(['phone' => '02100000000']);

    $this->artisan('sales:backfill-party-snapshots', ['--apply' => true])
        ->expectsOutputToContain('[no_evidence] LEGACY-SPARSE-CONTACT')
        ->assertSuccessful();

    expect(DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot'))->toBeNull();
});

it('does not fabricate a snapshot when complete Party audit evidence is missing', function () {
    $context = salesSnapshotBackfillContext();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    $party = Party::withoutEvents(fn (): Party => Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'NO-EVIDENCE-CUSTOMER',
        'name' => 'مشتری بدون شواهد',
    ]));
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-05-02 09:00:00'));
    $invoice = createLegacySnapshotInvoice($context, $customer, 'LEGACY-NO-EVIDENCE');

    $this->artisan('sales:backfill-party-snapshots', ['--apply' => true])
        ->expectsOutputToContain('[no_evidence] LEGACY-NO-EVIDENCE')
        ->assertSuccessful();

    expect(DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot'))->toBeNull()
        ->and(AuditLog::query()->where('event', 'sales_snapshot_backfilled')->count())->toBe(0);
});

it('reports invalid and different existing snapshots without overwriting them', function () {
    $context = salesSnapshotBackfillContext();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'EXISTING-SNAPSHOT-CUSTOMER',
        'entity_type' => 'individual',
        'name' => 'مشتری شواهد کامل',
        'phone' => null,
        'email' => null,
        'national_id' => null,
        'tax_id' => null,
        'address' => null,
        'version' => 0,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);
    $validSnapshot = app(PartySnapshotService::class)->capture($party);
    $differentSnapshot = [...$validSnapshot, 'name' => 'نام متفاوت snapshot'];

    $this->travelTo(CarbonImmutable::parse('2026-05-02 09:00:00'));
    $invalid = createLegacySnapshotInvoice($context, $customer, 'LEGACY-INVALID-SNAPSHOT');
    $different = createLegacySnapshotInvoice($context, $customer, 'LEGACY-DIFFERENT-SNAPSHOT');
    $malformed = createLegacySnapshotInvoice($context, $customer, 'LEGACY-MALFORMED-SNAPSHOT');

    DB::table('invoices')->where('id', $invalid->id)->update([
        'party_snapshot' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
    ]);
    DB::table('invoices')->where('id', $different->id)->update([
        'party_snapshot' => json_encode($differentSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    DB::table('invoices')->where('id', $malformed->id)->update([
        'party_snapshot' => 'null',
    ]);

    $this->artisan('sales:backfill-party-snapshots', ['--apply' => true])
        ->expectsOutputToContain('[invalid_snapshot] LEGACY-INVALID-SNAPSHOT')
        ->expectsOutputToContain('[snapshot_mismatch] LEGACY-DIFFERENT-SNAPSHOT')
        ->expectsOutputToContain('[invalid_snapshot] LEGACY-MALFORMED-SNAPSHOT')
        ->assertSuccessful();

    expect(DB::table('invoices')->where('id', $invalid->id)->value('party_snapshot'))
        ->toBe(json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR))
        ->and(DB::table('invoices')->where('id', $different->id)->value('party_snapshot'))
        ->toBe(json_encode($differentSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        ->and(DB::table('invoices')->where('id', $malformed->id)->value('party_snapshot'))->toBe('null')
        ->and(AuditLog::query()->where('event', 'sales_snapshot_backfilled')->count())->toBe(0);
});

it('rejects conflicting dry-run and apply options', function () {
    $context = salesSnapshotBackfillContext();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'CONFLICT-CUSTOMER',
        'name' => 'مشتری گزینه‌های متعارض',
    ]);
    $customer = Customer::query()->create(['party_id' => $party->id, 'is_active' => true]);
    $invoice = createLegacySnapshotInvoice($context, $customer, 'LEGACY-CONFLICT');

    $this->artisan('sales:backfill-party-snapshots', ['--dry-run' => true, '--apply' => true])
        ->expectsOutputToContain('--dry-run and --apply cannot be used together')
        ->assertFailed();

    expect(DB::table('invoices')->where('id', $invoice->id)->value('party_snapshot'))->toBeNull()
        ->and(AuditLog::query()->where('event', 'sales_snapshot_backfilled')->count())->toBe(0);
});
