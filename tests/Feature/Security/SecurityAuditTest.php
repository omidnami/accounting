<?php

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

it('audits two-factor security events without storing secrets', function () {
    $user = User::factory()->create();

    TwoFactorAuthenticationEnabled::dispatch($user);
    TwoFactorAuthenticationFailed::dispatch($user);
    RecoveryCodeReplaced::dispatch($user, 'sensitive-recovery-code');

    expect(AuditLog::query()->where('auditable_id', $user->id)->pluck('event')->all())
        ->toContain('auth.2fa_enabled', 'auth.2fa_failed', 'auth.recovery_code_replaced')
        ->and(json_encode(AuditLog::query()->where('auditable_id', $user->id)->get()))
        ->not->toContain('sensitive-recovery-code');
});
