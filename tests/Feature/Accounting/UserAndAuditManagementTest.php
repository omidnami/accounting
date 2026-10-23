<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

function userManagementUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

/**
 * @return array<string, mixed>
 */
function userManagementPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'کاربر آزمایشی',
        'email' => 'managed-user@example.com',
        'role' => 'accountant',
        'password' => 'Secure-Pass-123',
        'password_confirmation' => 'Secure-Pass-123',
        'version' => 0,
    ], $overrides);
}

it('protects user management and audit logs by role', function () {
    $this->get(route('users.index'))->assertRedirect(route('login'));
    $this->get(route('audit-logs.index'))->assertRedirect(route('login'));
    $accountant = userManagementUser(UserRole::Accountant);

    $this->actingAs($accountant)
        ->get(route('users.index'))
        ->assertForbidden();

    $this->actingAs($accountant)
        ->get(route('audit-logs.index'))
        ->assertForbidden();
});

it('allows a manager to view users and audit logs', function () {
    $manager = userManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Index')
            ->has('users.data')
            ->has('summary'));

    $this->actingAs($manager)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/Index')
            ->has('logs.data')
            ->has('model_options')
            ->has('summary'));
});

it('creates a verified user and audits the event without exposing password', function () {
    $manager = userManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->post(route('users.store'), userManagementPayload())
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'managed-user@example.com')->firstOrFail();
    $log = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'created')
        ->firstOrFail();

    expect($user->role)->toBe(UserRole::Accountant)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->version)->toBe(0)
        ->and(Hash::check('Secure-Pass-123', $user->password))->toBeTrue()
        ->and($log->user_id)->toBe($manager->id)
        ->and($log->new_values['password'])->toBe('[redacted]')
        ->and(json_encode([$log->old_values, $log->new_values]))
        ->not->toContain('Secure-Pass-123');
});

it('updates user role and password with version protection and audit trail', function () {
    $manager = userManagementUser(UserRole::Manager);
    $target = userManagementUser(UserRole::Viewer);
    $oldPassword = $target->password;

    $this->actingAs($manager)
        ->put(route('users.update', $target), userManagementPayload([
            'role' => 'cashier',
            'password' => 'Changed-Pass-456',
            'password_confirmation' => 'Changed-Pass-456',
            'version' => $target->version,
        ]))
        ->assertRedirect(route('users.index'))
        ->assertSessionHasNoErrors();

    $target->refresh();
    $log = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $target->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($target->role)->toBe(UserRole::Cashier)
        ->and($target->version)->toBe(1)
        ->and($target->password)->not->toBe($oldPassword)
        ->and($log->old_values['password'])->toBe('[redacted]')
        ->and($log->new_values['password'])->toBe('[redacted]')
        ->and(json_encode([$log->old_values, $log->new_values]))
        ->not->toContain('Changed-Pass-456');

    $this->actingAs($manager)
        ->put(route('users.update', $target), userManagementPayload([
            'role' => 'viewer',
            'password' => '',
            'password_confirmation' => '',
            'version' => 0,
        ]))
        ->assertSessionHasErrors('user');

    expect($target->refresh()->role)->toBe(UserRole::Cashier);
});

it('prevents a manager from changing their own role', function () {
    $manager = userManagementUser(UserRole::Manager);

    $this->actingAs($manager)
        ->put(route('users.update', $manager), userManagementPayload([
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => 'accountant',
            'password' => '',
            'password_confirmation' => '',
            'version' => $manager->version,
        ]))
        ->assertSessionHasErrors('user');

    expect($manager->refresh()->role)->toBe(UserRole::Manager);
});

it('filters audit logs by event and entity', function () {
    $manager = userManagementUser(UserRole::Manager);
    $target = userManagementUser(UserRole::Viewer);
    $target->update(['name' => 'نام ویرایش‌شده']);

    $this->actingAs($manager)
        ->get(route('audit-logs.index', [
            'event' => 'updated',
            'entity' => 'User',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.event', 'updated')
            ->where('filters.entity', 'User')
            ->where('logs.total', fn ($total) => $total >= 1));
});

it('keeps audit records immutable', function () {
    userManagementUser(UserRole::Viewer);
    $log = AuditLog::query()->firstOrFail();

    expect(fn () => $log->update(['description' => 'tampered']))
        ->toThrow(DomainException::class)
        ->and(fn () => $log->delete())
        ->toThrow(DomainException::class);
});
