<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

function personalActivityLog(User $actor, array $attributes = []): AuditLog
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

it('requires authentication for personal activity', function () {
    $this->get(route('my-activity.index'))->assertRedirect(route('login'));
});

it('limits every role to its own actions even when another actor is requested', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $other = User::factory()->create();
    $own = personalActivityLog($user, ['auditable_type' => User::class, 'auditable_id' => $other->id]);
    personalActivityLog($other, ['auditable_type' => User::class, 'auditable_id' => $user->id, 'description' => 'other-user-secret']);
    personalActivityLog($other, ['user_id' => null, 'description' => 'system-secret']);

    $this->actingAs($user)
        ->get(route('my-activity.index', ['user_id' => $other->id, 'actor_id' => $other->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Activity')
            ->where('activities.total', 1)
            ->where('activities.data.0.id', $own->id)
            ->where('activities.data.0.entity_label', 'حساب کاربری')
            ->where('activities.data.0.event_label', 'ایجاد')
            ->missing('activities.data.0.old_values')
            ->missing('activities.data.0.new_values')
            ->missing('activities.data.0.actor_email')
            ->missing('activities.data.0.ip_address')
            ->missing('activities.data.0.user_agent'));

    if ($role !== UserRole::Manager) {
        $this->get(route('audit-logs.index'))->assertForbidden();
    }
})->with(UserRole::cases());

it('paginates personal history newest first and preserves the date filter', function () {
    $user = User::factory()->create();
    $ids = [];

    for ($index = 0; $index < 16; $index++) {
        $ids[] = personalActivityLog($user)->id;
    }

    $this->actingAs($user)
        ->get(route('my-activity.index', ['date' => 'today']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.total', 16)
            ->where('activities.per_page', 15)
            ->has('activities.data', 15)
            ->where('activities.data.0.id', $ids[15])
            ->where('activities.data.14.id', $ids[1])
            ->where('activities.next_page_url', fn ($url): bool => str_contains($url, 'date=today') && str_contains($url, 'page=2')));
    $this->get(route('my-activity.index', ['date' => 'today', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.current_page', 2)
            ->has('activities.data', 1)
            ->where('activities.data.0.id', $ids[0])
            ->where('activities.next_page_url', null));
});

it('filters history dates and handles custom and unknown audit events', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = User::factory()->create();
    personalActivityLog($user, ['created_at' => now()->subDays(8)]);
    personalActivityLog($user, ['event' => 'custom.event', 'description' => 'Invoice custom.event']);
    $latest = personalActivityLog($user, ['event' => 'pos.sale', 'description' => 'Invoice pos.sale']);

    $this->actingAs($user)
        ->get(route('my-activity.index', ['date' => '7_days']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.total', 2)
            ->where('activities.data.0.id', $latest->id)
            ->where('activities.data.0.description', 'ثبت فروش صندوق فاکتور')
            ->where('activities.data.1.event_label', 'ثبت رویداد'));

    $this->get(route('my-activity.index', ['date' => 'invalid']))->assertSessionHasErrors('date');
    $this->get(route('my-activity.index', ['page' => 0]))->assertSessionHasErrors('page');
});

it('renders an empty history and the linked personal settings pages', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('my-activity.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.total', 0)
            ->has('activities.data', 0));
    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('appearance.edit'))->assertOk();
});
