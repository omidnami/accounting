<?php

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PrintEvent;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Store;
use App\Models\User;
use App\Services\Parties\PartySnapshotService;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\PrintStationService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{store: Store, branch: Branch, manager: User}
 */
function printStationLifecycleContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();

    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch()->firstOrFail();
    $manager = User::factory()->create(['branch_id' => $branch->id]);
    $manager->forceFill(['role' => UserRole::Manager])->save();

    return compact('store', 'branch', 'manager');
}

/**
 * @param  array{store: Store, branch: Branch}  $context
 * @param  array<string, mixed>  $attributes
 */
function lifecyclePrintStation(
    array $context,
    ?string $token = null,
    array $attributes = [],
): PrintStation {
    $token ??= Str::random(64);

    return PrintStation::query()->create(array_replace([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'name' => 'Lifecycle Station',
        'platform' => 'linux',
        'token_hash' => hash('sha256', $token),
        'status' => 'active',
    ], $attributes));
}

function lifecyclePrintJob(PrintStation $station, string $status = 'queued'): PrintJob
{
    return PrintJob::query()->create([
        'id' => (string) Str::uuid(),
        'store_id' => $station->store_id,
        'branch_id' => $station->branch_id,
        'print_station_id' => $station->id,
        'document_type' => 'sales_invoice',
        'document_id' => 1,
        'template' => 'sales_receipt',
        'payload' => [],
        'status' => $status,
        'attempts' => $status === 'claimed' ? 1 : 0,
        'expires_at' => now()->addMinutes(10),
        'claimed_at' => $status === 'claimed' ? now() : null,
        'printed_at' => $status === 'printed' ? now() : null,
        'last_error' => $status === 'failed' ? 'Previous failure' : null,
    ]);
}

/**
 * @param  array{store: Store, branch: Branch, manager: User}  $context
 */
function lifecycleSalesInvoice(array $context): Invoice
{
    $party = Party::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'PRINT-CUSTOMER-'.Str::random(8),
        'name' => 'Print Customer',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
        'is_active' => true,
    ]);
    $invoice = new Invoice([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'fiscal_year_id' => $context['store']->fiscalYears()->where('is_current', true)->value('id'),
        'invoice_number' => 'PRINT-'.Str::random(10),
        'type' => InvoiceType::Sales,
        'invoice_date' => now()->toDateString(),
        'customer_id' => $customer->id,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $context['manager']->id,
    ]);
    $invoice->status = InvoiceStatus::Confirmed;
    $invoice->save();

    return $invoice;
}

it('protects station lifecycle routes by manager role and active branch scope', function () {
    $context = printStationLifecycleContext();
    $station = lifecyclePrintStation($context, 'permission-token');
    $otherBranch = Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'INACTIVE-PRINT',
        'name' => 'Inactive Print Branch',
        'is_default' => false,
        'is_active' => false,
    ]);
    $otherStation = lifecyclePrintStation([
        'store' => $context['store'],
        'branch' => $otherBranch,
    ], 'other-branch-token');
    $accountant = User::factory()->create(['branch_id' => $context['branch']->id]);
    $accountant->forceFill(['role' => UserRole::Accountant])->save();

    $this->post(route('print-stations.rotate', $station))->assertRedirect(route('login'));
    $this->post(route('print-stations.revoke', $station))->assertRedirect(route('login'));

    $this->actingAs($accountant)
        ->get(route('print-settings.edit'))
        ->assertForbidden();
    $this->actingAs($accountant)
        ->post(route('print-stations.rotate', $station))
        ->assertForbidden();
    $this->actingAs($accountant)
        ->post(route('print-stations.revoke', $station))
        ->assertForbidden();

    $this->actingAs($context['manager'])
        ->get(route('print-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Print')
            ->has('stations', 1)
            ->where('stations.0.id', $station->id)
            ->where('stations.0.connection_status', 'never-seen')
            ->missing('stations.0.token_hash'));

    $this->actingAs($context['manager'])
        ->post(route('print-stations.rotate', $otherStation))
        ->assertNotFound();
    $this->actingAs($context['manager'])
        ->post(route('print-stations.revoke', $otherStation))
        ->assertNotFound();

    expect($station->refresh()->status)->toBe('active')
        ->and($station->token_hash)->toBe(hash('sha256', 'permission-token'))
        ->and($otherStation->refresh()->status)->toBe('active');
});

it('rotates a station token once while preserving identity and auditing without secrets', function () {
    $context = printStationLifecycleContext();
    $oldToken = 'old-lifecycle-token';
    $station = lifecyclePrintStation($context, $oldToken, [
        'last_seen_at' => now()->subMinute(),
    ]);
    $job = lifecyclePrintJob($station);
    $stationId = $station->id;
    $oldHash = $station->token_hash;

    $this->actingAs($context['manager'])
        ->post(route('print-stations.rotate', $station))
        ->assertRedirect(route('print-settings.edit'));

    $flash = session(SessionKey::FLASH_DATA);
    $newToken = $flash['print_station_token']['token'];

    expect($flash['print_station_token']['station_id'])->toBe($stationId)
        ->and($newToken)->toHaveLength(64)
        ->and($newToken)->not->toBe($oldToken)
        ->and($station->refresh()->id)->toBe($stationId)
        ->and($station->token_hash)->toBe(hash('sha256', $newToken))
        ->and($station->token_hash)->not->toBe($oldHash)
        ->and($station->last_seen_at)->toBeNull()
        ->and($job->refresh()->status)->toBe('queued')
        ->and(DB::table('print_stations')->where('id', $stationId)->value('token_hash'))
        ->toBe(hash('sha256', $newToken));

    $audit = AuditLog::query()
        ->where('auditable_type', PrintStation::class)
        ->where('auditable_id', $stationId)
        ->where('event', 'rotated')
        ->latest('id')
        ->firstOrFail();

    expect($audit->user_id)->toBe($context['manager']->id)
        ->and($audit->old_values)->not->toHaveKey('token_hash')
        ->and($audit->new_values)->not->toHaveKey('token_hash')
        ->and(json_encode($audit->getAttributes()))->not->toContain($oldToken)
        ->and(json_encode($audit->getAttributes()))->not->toContain($newToken)
        ->and(json_encode($audit->getAttributes()))->not->toContain($oldHash)
        ->and(json_encode($audit->getAttributes()))->not->toContain(hash('sha256', $newToken));

    $this->actingAs($context['manager'])
        ->get(route('print-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stations.0.connection_status', 'never-seen')
            ->missing('stations.0.token_hash')
            ->hasFlash('print_station_token.token', $newToken));

    $this->actingAs($context['manager'])
        ->get(route('print-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missingFlash('print_station_token'));
});

it('rejects the old token on every agent endpoint and accepts the new token', function () {
    $context = printStationLifecycleContext();
    $oldToken = 'old-agent-token';
    $station = lifecyclePrintStation($context, $oldToken);
    $job = lifecyclePrintJob($station);

    $this->actingAs($context['manager'])
        ->post(route('print-stations.rotate', $station))
        ->assertRedirect(route('print-settings.edit'));
    $newToken = session(SessionKey::FLASH_DATA)['print_station_token']['token'];

    $this->withHeader('Authorization', 'Bearer '.$oldToken)
        ->postJson('/api/print-agent/claim')
        ->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$oldToken)
        ->postJson('/api/print-agent/jobs/'.$job->id.'/heartbeat')
        ->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$oldToken)
        ->postJson('/api/print-agent/jobs/'.$job->id.'/complete', ['status' => 'printed'])
        ->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer '.$newToken)
        ->postJson('/api/print-agent/claim')
        ->assertOk()
        ->assertJsonPath('job.id', $job->id);
    $this->withHeader('Authorization', 'Bearer '.$newToken)
        ->postJson('/api/print-agent/jobs/'.$job->id.'/heartbeat')
        ->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$newToken)
        ->postJson('/api/print-agent/jobs/'.$job->id.'/complete', ['status' => 'printed'])
        ->assertOk();

    expect($job->refresh()->status)->toBe('printed')
        ->and($station->refresh()->last_seen_at)->not->toBeNull();
});

it('revokes a station and fails only queued or claimed jobs with print events', function () {
    $context = printStationLifecycleContext();
    $token = 'revoke-agent-token';
    $station = lifecyclePrintStation($context, $token, [
        'last_seen_at' => now(),
    ]);
    $queued = lifecyclePrintJob($station);
    $claimed = lifecyclePrintJob($station, 'claimed');
    $printed = lifecyclePrintJob($station, 'printed');
    $failed = lifecyclePrintJob($station, 'failed');
    $stationId = $station->id;
    $tokenHash = $station->token_hash;
    $claimedAt = $claimed->claimed_at;

    $this->actingAs($context['manager'])
        ->post(route('print-stations.revoke', $station))
        ->assertRedirect(route('print-settings.edit'));

    expect($station->refresh()->id)->toBe($stationId)
        ->and($station->status)->toBe('revoked')
        ->and($station->revoked_at)->not->toBeNull()
        ->and($station->token_hash)->toBe($tokenHash)
        ->and($station->jobs()->count())->toBe(4)
        ->and($queued->refresh()->status)->toBe('failed')
        ->and($queued->last_error)->toBe('Print station revoked')
        ->and($claimed->refresh()->status)->toBe('failed')
        ->and($claimed->last_error)->toBe('Print station revoked')
        ->and($claimed->claimed_at->equalTo($claimedAt))->toBeTrue()
        ->and($printed->refresh()->status)->toBe('printed')
        ->and($printed->last_error)->toBeNull()
        ->and($failed->refresh()->status)->toBe('failed')
        ->and($failed->last_error)->toBe('Previous failure');

    foreach ([$queued, $claimed] as $job) {
        $event = PrintEvent::query()
            ->where('print_job_id', $job->id)
            ->where('event', 'failed')
            ->latest('id')
            ->firstOrFail();

        expect($event->print_station_id)->toBe($stationId)
            ->and($event->message)->toBe('Print station revoked');
    }

    expect(PrintEvent::query()->whereIn('print_job_id', [$printed->id, $failed->id])->count())->toBe(0);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/print-agent/claim')
        ->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/print-agent/jobs/'.$claimed->id.'/heartbeat')
        ->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/print-agent/jobs/'.$claimed->id.'/complete', ['status' => 'printed'])
        ->assertUnauthorized();

    $this->actingAs($context['manager'])
        ->post(route('print-stations.revoke', $station))
        ->assertRedirect(route('print-settings.edit'));

    $audit = AuditLog::query()
        ->where('auditable_type', PrintStation::class)
        ->where('auditable_id', $stationId)
        ->where('event', 'revoked')
        ->firstOrFail();

    expect(AuditLog::query()
        ->where('auditable_type', PrintStation::class)
        ->where('auditable_id', $stationId)
        ->where('event', 'revoked')
        ->count())->toBe(1)
        ->and(PrintEvent::query()->where('event', 'failed')->count())->toBe(2)
        ->and($audit->new_values)->not->toHaveKey('token_hash')
        ->and(json_encode($audit->getAttributes()))->not->toContain($token)
        ->and(json_encode($audit->getAttributes()))->not->toContain($tokenHash)
        ->and(fn () => app(PrintStationService::class)->rotate($station->refresh()))
        ->toThrow(DomainException::class);
});

it('prints the snapshotted customer identity instead of the current party name', function () {
    $context = printStationLifecycleContext();
    $station = lifecyclePrintStation($context, null, ['last_seen_at' => now()]);
    $invoice = lifecycleSalesInvoice($context);
    $snapshot = app(PartySnapshotService::class)->capture($invoice->customer->party);
    $snapshot['name'] = 'نام تاریخی چاپ';
    $snapshot['phone'] = '02100000000';
    DB::table('invoices')->where('id', $invoice->id)->update([
        'party_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ]);
    $invoice->customer->party->update(['name' => 'نام جدید', 'phone' => '02111111111']);
    $invoice->refresh();

    $job = app(PrintJobService::class)->queueSalesInvoice($invoice);

    expect($job->payload['customer']['name'])->toBe('نام تاریخی چاپ')
        ->and($job->payload['customer']['phone'])->toBe('02100000000')
        ->and($job->print_station_id)->toBe($station->id);
});

it('uses the configured online threshold and treats never-seen stations as offline', function () {
    $this->freezeTime();
    $context = printStationLifecycleContext();
    $neverSeen = lifecyclePrintStation($context, null, ['name' => 'Never seen']);
    $recent = lifecyclePrintStation($context, null, [
        'name' => 'Recent',
        'last_seen_at' => now()->subSeconds(299),
    ]);
    $boundary = lifecyclePrintStation($context, null, [
        'name' => 'Boundary',
        'last_seen_at' => now()->subSeconds(300),
    ]);
    $stale = lifecyclePrintStation($context, null, [
        'name' => 'Stale',
        'last_seen_at' => now()->subSeconds(301),
    ]);
    $revoked = lifecyclePrintStation($context, null, [
        'name' => 'Revoked',
        'status' => 'revoked',
        'revoked_at' => now(),
        'last_seen_at' => now(),
    ]);
    $inactive = lifecyclePrintStation($context, null, [
        'name' => 'Inactive',
        'status' => 'inactive',
        'last_seen_at' => now(),
    ]);

    expect(config('print_agent.online_threshold_seconds'))->toBe(300)
        ->and($neverSeen->isOnline())->toBeFalse()
        ->and($recent->isOnline())->toBeTrue()
        ->and($boundary->isOnline())->toBeTrue()
        ->and($stale->isOnline())->toBeFalse()
        ->and($revoked->isOnline())->toBeFalse()
        ->and($inactive->isOnline())->toBeFalse()
        ->and(PrintStation::query()->online()->orderBy('id')->pluck('id')->all())
        ->toBe([$recent->id, $boundary->id]);
});

it('uses the same online scope for print jobs and sales print availability', function () {
    $context = printStationLifecycleContext();
    $invoice = lifecycleSalesInvoice($context);
    $neverSeen = lifecyclePrintStation($context, null, ['name' => 'Never seen']);
    $stale = lifecyclePrintStation($context, null, [
        'name' => 'Stale',
        'last_seen_at' => now()->subSeconds(301),
    ]);

    expect(PrintStation::query()->online()->count())->toBe(0)
        ->and(fn () => app(PrintJobService::class)->queueSalesInvoice($invoice))
        ->toThrow(DomainException::class);

    $this->actingAs($context['manager'])
        ->get(route('sales.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Show')
            ->where('has_print_station', false));

    $online = lifecyclePrintStation($context, null, [
        'name' => 'Online',
        'last_seen_at' => now(),
    ]);
    $job = app(PrintJobService::class)->queueSalesInvoice($invoice);

    expect($job->print_station_id)->toBe($online->id)
        ->and($neverSeen->isOnline())->toBeFalse()
        ->and($stale->isOnline())->toBeFalse();

    $this->actingAs($context['manager'])
        ->get(route('sales.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('has_print_station', true));

    $online->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

    $this->actingAs($context['manager'])
        ->get(route('sales.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('has_print_station', false));
});
