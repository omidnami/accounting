<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Security\SessionSecurityService;
use App\Services\Users\UserDeletionService;
use App\Services\Users\UserManagementService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @return array<string, mixed>
 */
function userLifecycleServiceContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $cashAccount = TreasuryAccount::query()->where('branch_id', $branch->id)->where('code', 'CASH')->firstOrFail();
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    return compact('store', 'branch', 'warehouse', 'cashAccount', 'manager');
}

/**
 * @return array<string, mixed>
 */
function userManagementServicePayload(array $overrides = []): array
{
    return array_replace([
        'name' => ' کاربر آزمایشی ',
        'email' => ' Managed-User@Example.COM ',
        'role' => 'accountant',
        'password' => 'Secure-Pass-123',
        'version' => 0,
    ], $overrides);
}

function userLifecycleSession(User $user, string $id): void
{
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '192.0.2.20',
        'user_agent' => 'service-test',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);
}

it('creates a verified normalized user and a redacted audit trail directly', function () {
    $context = userLifecycleServiceContext();
    $this->actingAs($context['manager']);

    $user = app(UserManagementService::class)->create(userManagementServicePayload() + [
        'branch_id' => $context['branch']->id,
        'is_active' => false,
    ]);
    $audit = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'created')
        ->firstOrFail();

    expect($user->name)->toBe('کاربر آزمایشی')
        ->and($user->email)->toBe('managed-user@example.com')
        ->and($user->role)->toBe(UserRole::Accountant)
        ->and($user->branch_id)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->version)->toBe(0)
        ->and(Hash::check('Secure-Pass-123', $user->password))->toBeTrue()
        ->and($audit->user_id)->toBe($context['manager']->id)
        ->and($audit->new_values['password'])->toBe('[redacted]')
        ->and(json_encode($audit->new_values))->not->toContain('Secure-Pass-123')
        ->and($context['manager']->canManageUsers())->toBeTrue();
});

it('updates user credentials and two-factor state while revoking only other sessions', function () {
    $context = userLifecycleServiceContext();
    $target = User::factory()->withTwoFactor()->create([
        'role' => UserRole::Viewer,
        'branch_id' => $context['branch']->id,
        'remember_token' => 'old-remember-token',
    ]);
    userLifecycleSession($target, 'keep-session');
    userLifecycleSession($target, 'revoke-session');
    $this->actingAs($context['manager']);

    $updated = app(UserManagementService::class)->update($target, userManagementServicePayload([
        'name' => 'صندوق‌دار جدید',
        'email' => 'NEW-CASHIER@EXAMPLE.COM',
        'role' => 'cashier',
        'password' => 'Changed-Pass-456',
        'version' => $target->version,
        'is_active' => true,
        'reset_two_factor' => true,
    ]), $context['manager'], 'keep-session');

    expect($updated->name)->toBe('صندوق‌دار جدید')
        ->and($updated->email)->toBe('new-cashier@example.com')
        ->and($updated->role)->toBe(UserRole::Cashier)
        ->and($updated->branch_id)->toBeNull()
        ->and($updated->is_active)->toBeTrue()
        ->and($updated->version)->toBe(1)
        ->and(Hash::check('Changed-Pass-456', $updated->password))->toBeTrue()
        ->and($updated->two_factor_secret)->toBeNull()
        ->and($updated->two_factor_recovery_codes)->toBeNull()
        ->and($updated->two_factor_confirmed_at)->toBeNull()
        ->and($updated->remember_token)->not->toBe('old-remember-token')
        ->and(DB::table('sessions')->where('id', 'keep-session')->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'revoke-session')->exists())->toBeFalse()
        ->and(AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $target->id)
            ->where('event', 'two_factor_reset')
            ->where('user_id', $context['manager']->id)
            ->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->where('auditable_id', $target->id)->get()))
        ->not->toContain('Changed-Pass-456');
});

it('rejects stale user versions without changing credentials or creating audit events', function () {
    $context = userLifecycleServiceContext();
    $target = User::factory()->create([
        'role' => UserRole::Viewer,
        'branch_id' => $context['branch']->id,
        'remember_token' => 'stable-token',
    ]);
    $originalPassword = $target->password;
    $auditCount = AuditLog::query()->count();

    expect(fn () => app(UserManagementService::class)->update($target, userManagementServicePayload([
        'name' => 'نام نباید ذخیره شود',
        'email' => 'stale@example.com',
        'role' => 'cashier',
        'password' => 'Should-Not-Stick-123',
        'version' => $target->version + 1,
    ]), $context['manager']))->toThrow(DomainException::class, 'هم‌زمان توسط مدیر دیگری');

    expect($target->refresh()->name)->not->toBe('نام نباید ذخیره شود')
        ->and($target->email)->not->toBe('stale@example.com')
        ->and($target->role)->toBe(UserRole::Viewer)
        ->and($target->branch_id)->toBe($context['branch']->id)
        ->and($target->version)->toBe(0)
        ->and($target->password)->toBe($originalPassword)
        ->and($target->remember_token)->toBe('stable-token')
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('protects managers from self role changes and self deactivation', function () {
    $context = userLifecycleServiceContext();
    $manager = $context['manager'];
    $service = app(UserManagementService::class);

    expect(fn () => $service->update($manager, userManagementServicePayload([
        'name' => $manager->name,
        'email' => $manager->email,
        'role' => 'accountant',
        'password' => '',
        'version' => $manager->version,
    ]), $manager))->toThrow(DomainException::class, 'نقش حساب کاربری خودتان');

    expect(fn () => $service->update($manager, userManagementServicePayload([
        'name' => $manager->name,
        'email' => $manager->email,
        'role' => 'manager',
        'password' => '',
        'version' => $manager->version,
        'is_active' => false,
    ]), $manager))->toThrow(DomainException::class, 'حساب کاربری خودتان');

    expect($manager->refresh()->role)->toBe(UserRole::Manager)
        ->and($manager->is_active)->toBeTrue()
        ->and($manager->version)->toBe(0);
});

it('preserves the last active manager and rejects duplicate email updates', function () {
    $context = userLifecycleServiceContext();
    $accountant = User::factory()->create(['role' => UserRole::Accountant]);
    $service = app(UserManagementService::class);

    expect(fn () => $service->update($context['manager'], userManagementServicePayload([
        'name' => $context['manager']->name,
        'email' => 'demoted@example.com',
        'role' => 'accountant',
        'password' => '',
        'version' => $context['manager']->version,
    ]), $accountant))->toThrow(DomainException::class, 'آخرین مدیر فعال سیستم');

    $target = User::factory()->create(['role' => UserRole::Viewer]);

    expect(fn () => $service->update($target, userManagementServicePayload([
        'name' => 'نام نباید ذخیره شود',
        'email' => $accountant->email,
        'role' => 'cashier',
        'password' => '',
        'version' => $target->version,
    ]), $context['manager']))->toThrow(DomainException::class, 'این ایمیل قبلاً');

    expect($context['manager']->refresh()->role)->toBe(UserRole::Manager)
        ->and($context['manager']->is_active)->toBeTrue()
        ->and($target->refresh()->role)->toBe(UserRole::Viewer)
        ->and($target->email)->not->toBe($accountant->email)
        ->and($accountant->canManageUsers())->toBeFalse();
});

it('blocks deactivation while a user has an open POS session', function () {
    $context = userLifecycleServiceContext();
    $target = User::factory()->create(['role' => UserRole::Cashier]);
    PosSession::query()->forceCreate([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'user_id' => $target->id,
        'warehouse_id' => $context['warehouse']->id,
        'cash_account_id' => $context['cashAccount']->id,
        'active_user_id' => $target->id,
        'active_cash_account_id' => $context['cashAccount']->id,
        'opening_cash' => 0,
        'opened_at' => now(),
    ]);

    expect(fn () => app(UserManagementService::class)->update($target, userManagementServicePayload([
        'name' => $target->name,
        'email' => $target->email,
        'role' => 'cashier',
        'password' => '',
        'version' => $target->version,
        'is_active' => false,
    ]), $context['manager']))->toThrow(DomainException::class, 'شیفت باز صندوق');

    expect($target->refresh()->is_active)->toBeTrue()
        ->and(PosSession::query()->where('user_id', $target->id)->whereNull('closed_at')->exists())->toBeTrue();
});

it('anonymizes a deleted user, revokes sessions and retains audit history', function () {
    $context = userLifecycleServiceContext();
    $target = User::factory()->withTwoFactor()->create([
        'name' => 'کاربر قابل حذف',
        'email' => 'delete-me@example.com',
        'role' => UserRole::Viewer,
        'branch_id' => $context['branch']->id,
        'remember_token' => 'old-delete-token',
    ]);
    userLifecycleSession($target, 'delete-session');
    $history = AuditLog::query()->forceCreate([
        'user_id' => $target->id,
        'actor_name' => $target->name,
        'actor_email' => $target->email,
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $target->id,
        'description' => 'تاریخچه باید بماند',
    ]);
    $oldPassword = $target->password;

    app(UserDeletionService::class)->delete($target);

    expect(User::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and($target->refresh()->name)->toBe('کاربر حذف‌شده')
        ->and($target->email)->toBe('deleted-'.$target->id.'@anonymous.local')
        ->and($target->password)->not->toBe($oldPassword)
        ->and(Hash::check('password', $target->password))->toBeFalse()
        ->and($target->branch_id)->toBeNull()
        ->and($target->is_active)->toBeFalse()
        ->and($target->two_factor_secret)->toBeNull()
        ->and($target->two_factor_recovery_codes)->toBeNull()
        ->and($target->two_factor_confirmed_at)->toBeNull()
        ->and($target->remember_token)->not->toBe('old-delete-token')
        ->and(DB::table('sessions')->where('id', 'delete-session')->exists())->toBeFalse()
        ->and($history->refresh()->user_id)->toBe($target->id)
        ->and($history->actor_name)->toBe('کاربر قابل حذف');
});

it('refuses to delete the last active manager without changing its credentials', function () {
    $context = userLifecycleServiceContext();
    $manager = $context['manager'];
    $originalPassword = $manager->password;
    $originalToken = $manager->remember_token;
    userLifecycleSession($manager, 'manager-session');

    expect(fn () => app(UserDeletionService::class)->delete($manager))
        ->toThrow(DomainException::class, 'آخرین مدیر سیستم قابل حذف نیست.');

    expect($manager->refresh()->is_active)->toBeTrue()
        ->and($manager->password)->toBe($originalPassword)
        ->and($manager->remember_token)->toBe($originalToken)
        ->and(DB::table('sessions')->where('id', 'manager-session')->exists())->toBeTrue();
});

it('rolls back user anonymization when session revocation fails', function () {
    $context = userLifecycleServiceContext();
    $target = User::factory()->withTwoFactor()->create([
        'name' => 'کاربر بدون تغییر',
        'email' => 'rollback@example.com',
        'role' => UserRole::Viewer,
        'branch_id' => $context['branch']->id,
        'remember_token' => 'rollback-token',
    ]);
    $originalPassword = $target->password;
    $this->mock(SessionSecurityService::class)
        ->shouldReceive('revokeAllSessions')
        ->once()
        ->andThrow(new RuntimeException('forced session revocation failure'));

    expect(fn () => app(UserDeletionService::class)->delete($target))
        ->toThrow(RuntimeException::class, 'forced session revocation failure');

    expect($target->refresh()->name)->toBe('کاربر بدون تغییر')
        ->and($target->email)->toBe('rollback@example.com')
        ->and($target->password)->toBe($originalPassword)
        ->and($target->branch_id)->toBe($context['branch']->id)
        ->and($target->is_active)->toBeTrue()
        ->and($target->two_factor_secret)->not->toBeNull()
        ->and($target->two_factor_recovery_codes)->not->toBeNull()
        ->and($target->two_factor_confirmed_at)->not->toBeNull()
        ->and($target->remember_token)->toBe('rollback-token');
});
