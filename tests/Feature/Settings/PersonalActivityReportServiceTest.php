<?php

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Reporting\PersonalActivityReportService;

/**
 * @return array<string, mixed>
 */
function personalActivityServiceLog(User $actor, array $attributes = []): AuditLog
{
    return AuditLog::query()->forceCreate([
        'user_id' => $actor->id,
        'actor_name' => $actor->name,
        'actor_email' => $actor->email,
        'event' => 'created',
        'auditable_type' => Invoice::class,
        'auditable_id' => 1,
        'description' => 'Invoice created',
        'old_values' => ['private' => 'old-secret'],
        'new_values' => ['private' => 'new-secret'],
        'ip_address' => '192.0.2.1',
        'user_agent' => 'private-agent',
        ...$attributes,
    ]);
}

it('scopes personal activity to the requested user and caps history at fifty rows', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = User::withoutEvents(fn (): User => User::factory()->create());
    $other = User::withoutEvents(fn (): User => User::factory()->create());
    $ids = [];

    for ($index = 0; $index < 60; $index++) {
        $ids[] = personalActivityServiceLog($user)->id;
    }
    personalActivityServiceLog($other, ['description' => 'other-user-secret']);
    personalActivityServiceLog($other, ['user_id' => null, 'description' => 'system-secret']);

    $page = app(PersonalActivityReportService::class)->paginate($user, 'today');

    expect($page->total())->toBe(50)
        ->and($page->perPage())->toBe(25)
        ->and($page->items())->toHaveCount(25)
        ->and($page->items()[0]['id'])->toBe($ids[59])
        ->and($page->items()[24]['id'])->toBe($ids[35])
        ->and(collect($page->items())->pluck('description'))->not->toContain('other-user-secret')
        ->and(collect($page->items())->pluck('description'))->not->toContain('system-secret');
});

it('returns safe personal activity details and applies date filters', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = User::withoutEvents(fn (): User => User::factory()->create());
    personalActivityServiceLog($user, ['created_at' => now()->subDays(8)]);
    $latest = personalActivityServiceLog($user, [
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $user->id,
        'description' => 'User updated',
        'new_values' => [
            'invoice_number' => 'SI-1405-123',
            'name' => 'فاکتور آزمایشی',
            'quantity' => 4,
            'status' => 'confirmed',
            'password' => 'password-secret',
            'private' => 'private-secret',
        ],
    ]);

    $page = app(PersonalActivityReportService::class)->paginate($user, '7_days');
    $row = $page->items()[0];

    expect($page->total())->toBe(1)
        ->and($row['id'])->toBe($latest->id)
        ->and($row['event_label'])->toBe('ویرایش')
        ->and($row['entity_label'])->toBe('حساب کاربری')
        ->and($row['description'])->toBe('ویرایش حساب کاربری')
        ->and($row['reference'])->toBe('SI-1405-123')
        ->and($row['record_name'])->toBe('فاکتور آزمایشی')
        ->and($row['changed_fields'])->toBe(['نام', 'وضعیت', 'تعداد'])
        ->and($row['ip_address'])->toBe('192.0.2.1')
        ->and(array_keys($row))->not->toContain('old_values')
        ->and(array_keys($row))->not->toContain('new_values')
        ->and(array_keys($row))->not->toContain('actor_email')
        ->and(array_keys($row))->not->toContain('user_agent')
        ->and(json_encode($row))->not->toContain('password-secret')
        ->and(json_encode($row))->not->toContain('private-secret');
});

it('returns an empty personal history for a user without audit events', function () {
    $user = User::withoutEvents(fn (): User => User::factory()->create());

    $page = app(PersonalActivityReportService::class)->paginate($user);

    expect($page->total())->toBe(0)
        ->and($page->items())->toBeEmpty();
});
