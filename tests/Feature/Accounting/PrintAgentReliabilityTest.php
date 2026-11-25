<?php

use App\Models\Branch;
use App\Models\PrintEvent;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function printAgentContext(): array
{
    $store = Store::query()->create([
        'code' => 'PRINT-STORE',
        'name' => 'Print Store',
        'timezone' => 'Asia/Tehran',
    ]);
    $branch = Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'MAIN',
        'name' => 'Main Branch',
    ]);
    $token = 'print-agent-test-token';
    $station = PrintStation::query()->create([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'name' => 'Test Station',
        'platform' => 'linux',
        'token_hash' => hash('sha256', $token),
        'status' => 'active',
    ]);
    $job = PrintJob::query()->create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'print_station_id' => $station->id,
        'document_type' => 'sales_invoice',
        'document_id' => 1,
        'template' => 'sales_receipt',
        'payload' => [],
        'status' => 'queued',
        'expires_at' => now()->addMinutes(10),
    ]);

    return compact('store', 'branch', 'station', 'token', 'job');
}

it('reclaims expired print leases and retries up to the configured limit', function () {
    config(['print_agent.lease_seconds' => 180, 'print_agent.max_attempts' => 3]);
    $context = printAgentContext();
    $context['job']->forceFill([
        'status' => 'claimed',
        'claimed_at' => now()->subMinutes(10),
        'attempts' => 1,
    ])->save();

    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson('/api/print-agent/claim')
        ->assertOk()
        ->assertJsonPath('job.id', $context['job']->id);

    expect($context['job']->refresh()->attempts)->toBe(2)
        ->and($context['job']->status)->toBe('claimed')
        ->and(PrintEvent::query()->where('event', 'reclaimed')->count())->toBe(1);

    $context['job']->forceFill([
        'claimed_at' => now()->subMinutes(10),
        'attempts' => 3,
    ])->save();

    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson('/api/print-agent/claim')
        ->assertOk()
        ->assertJsonPath('job', null);

    expect($context['job']->refresh()->status)->toBe('failed');
});

it('extends a claimed print lease through the heartbeat endpoint', function () {
    $context = printAgentContext();
    $context['job']->forceFill([
        'status' => 'claimed',
        'claimed_at' => now()->subMinutes(10),
        'attempts' => 1,
    ])->save();

    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson('/api/print-agent/jobs/'.$context['job']->id.'/heartbeat')
        ->assertOk();

    expect($context['job']->refresh()->claimed_at->greaterThan(now()->subMinute()))
        ->toBeTrue();
});

it('makes print completion idempotent and rejects conflicting retries', function () {
    $context = printAgentContext();

    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson('/api/print-agent/claim')
        ->assertOk();

    $url = '/api/print-agent/jobs/'.$context['job']->id.'/complete';
    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson($url, ['status' => 'printed'])
        ->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson($url, ['status' => 'printed'])
        ->assertOk();

    expect(PrintEvent::query()->where('print_job_id', $context['job']->id)
        ->where('event', 'printed')->count())->toBe(1);

    $this->withHeader('Authorization', 'Bearer '.$context['token'])
        ->postJson($url, ['status' => 'failed'])
        ->assertStatus(409);
});

it('never sends a print station token over plain HTTP', function () {
    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('print-agent:run', [
        '--server' => 'http://prints.example.com',
        '--token' => 'sensitive-station-token',
        '--once' => true,
    ])->assertFailed();

    Http::assertNothingSent();
});

it('allows plain HTTP only for localhost development and does not follow redirects', function () {
    Http::fake([
        'http://localhost:8000/api/print-agent/claim' => Http::response(['job' => null]),
        'https://prints.example.com/api/print-agent/claim' => Http::response('', 302, [
            'Location' => 'http://prints.example.com/api/print-agent/claim',
        ]),
    ]);

    $this->artisan('print-agent:run', [
        '--server' => 'http://localhost:8000',
        '--token' => 'local-token',
        '--once' => true,
    ])->assertSuccessful();

    $this->artisan('print-agent:run', [
        '--server' => 'https://prints.example.com',
        '--token' => 'remote-token',
        '--once' => true,
    ])->assertFailed();

    Http::assertSentCount(2);
});
