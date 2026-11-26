<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use App\Services\Reporting\AuditLogReportService;
use Illuminate\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function auditLogReportContext(): array
{
    $store = Store::withoutEvents(fn (): Store => Store::query()->create([
        'code' => 'AUDIT-REPORT-MAIN',
        'name' => 'فروشگاه گزارش ممیزی',
        'is_active' => true,
    ]));
    $otherStore = Store::withoutEvents(fn (): Store => Store::query()->create([
        'code' => 'AUDIT-REPORT-OTHER',
        'name' => 'فروشگاه دیگر گزارش ممیزی',
        'is_active' => true,
    ]));
    $actor = User::withoutEvents(fn (): User => User::factory()->create([
        'name' => 'مدیر ممیزی',
        'email' => 'audit-manager@example.com',
        'role' => UserRole::Manager,
    ]));
    $accountant = User::withoutEvents(fn (): User => User::factory()->create([
        'role' => UserRole::Accountant,
    ]));

    $global = AuditLog::query()->forceCreate([
        'user_id' => $actor->id,
        'actor_name' => $actor->name,
        'actor_email' => $actor->email,
        'event' => 'created',
        'auditable_type' => User::class,
        'auditable_id' => $actor->id,
        'description' => 'کاربر سیستم ایجاد شد',
        'created_at' => now(),
    ]);
    $current = AuditLog::query()->forceCreate([
        'store_id' => $store->id,
        'user_id' => $actor->id,
        'actor_name' => $actor->name,
        'actor_email' => $actor->email,
        'event' => 'updated',
        'auditable_type' => Invoice::class,
        'auditable_id' => 101,
        'description' => 'فاکتور قابل جستجو',
        'old_values' => ['total' => '100.00'],
        'new_values' => ['total' => '125.00'],
        'ip_address' => '192.0.2.10',
        'created_at' => now(),
    ]);
    $historical = AuditLog::query()->forceCreate([
        'store_id' => $store->id,
        'actor_name' => 'کاربر قبلی',
        'actor_email' => 'former@example.com',
        'event' => 'updated',
        'auditable_type' => Invoice::class,
        'auditable_id' => 102,
        'description' => 'ویرایش تاریخی فاکتور',
        'old_values' => ['status' => 'draft'],
        'new_values' => ['status' => 'confirmed'],
        'created_at' => now()->subDays(8),
    ]);
    $branch = AuditLog::query()->forceCreate([
        'store_id' => $store->id,
        'event' => 'deleted',
        'auditable_type' => Branch::class,
        'auditable_id' => 103,
        'description' => 'شعبه حذف شد',
        'created_at' => now()->subDays(2),
    ]);
    AuditLog::query()->forceCreate([
        'store_id' => $otherStore->id,
        'event' => 'updated',
        'auditable_type' => Invoice::class,
        'auditable_id' => 999,
        'description' => 'other-store-secret',
        'created_at' => now(),
    ]);

    return compact(
        'store',
        'otherStore',
        'actor',
        'accountant',
        'global',
        'current',
        'historical',
        'branch',
    );
}

it('scopes audit summaries and filtered pagination to the store and global logs', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $context = auditLogReportContext();
    $service = app(AuditLogReportService::class);

    $page = $service->paginate($context['store'], [], 2);
    $filtered = $service->paginate($context['store'], [
        'event' => 'updated',
        'entity' => 'Invoice',
        'date' => 'today',
    ]);
    $byActor = $service->paginate($context['store'], ['search' => $context['actor']->email]);

    expect($service->summary($context['store']))->toBe([
        'total' => 4,
        'today' => 2,
        'security' => 1,
        'financial' => 2,
    ])
        ->and($page->total())->toBe(4)
        ->and($page->items())->toHaveCount(2)
        ->and(collect($page->items())->pluck('id'))->not->toContain(999)
        ->and($filtered->total())->toBe(1)
        ->and($filtered->items()[0]->id)->toBe($context['current']->id)
        ->and(collect($byActor->items())->pluck('id'))->toContain($context['global']->id)
        ->and(collect($byActor->items())->pluck('id'))->toContain($context['current']->id)
        ->and(array_keys($service->modelMap()))->toBe(collect($service->modelMap())->keys()->sort()->values()->all())
        ->and($context['actor']->canViewAuditLogs())->toBeTrue()
        ->and($context['accountant']->canViewAuditLogs())->toBeFalse();
});

it('presents live actors and preserved actor snapshots without losing audit values', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $context = auditLogReportContext();
    $service = app(AuditLogReportService::class);

    $current = $service->present($context['current']->refresh());
    $historical = $service->present($context['historical']->refresh());

    expect($current['entity'])->toBe('Invoice')
        ->and($current['actor'])->toBe([
            'name' => $context['actor']->name,
            'email' => $context['actor']->email,
        ])
        ->and($current['old_values'])->toBe(['total' => '100.00'])
        ->and($current['new_values'])->toBe(['total' => '125.00'])
        ->and($historical['actor'])->toBe([
            'name' => 'کاربر قبلی',
            'email' => 'former@example.com',
        ])
        ->and($historical['new_values'])->toBe(['status' => 'confirmed']);
});

it('exports only filtered store audit rows with structured old and new values', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $context = auditLogReportContext();

    $response = app(AuditLogReportService::class)->export($context['store'], [
        'event' => 'updated',
        'entity' => 'Invoice',
    ]);
    $content = (new TestResponse($response))->streamedContent();
    $rows = collect(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))))
        ->map(fn (string $line): array => str_getcsv($line, ';', '"', ''))
        ->skip(1);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck(4))->not->toContain('other-store-secret')
        ->and($rows->firstWhere(5, 'مدیر ممیزی')[9])->toBe('{"total":"125.00"}')
        ->and($rows->firstWhere(5, 'کاربر قبلی')[9])->toBe('{"status":"confirmed"}')
        ->and($rows->firstWhere(5, 'کاربر قبلی')[6])->toBe('former@example.com');
});
