<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

class SecurityAuditListener
{
    public function handle(object $event): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        [$name, $user, $values, $description] = match (true) {
            $event instanceof Login => [
                'auth.login',
                $event->user,
                ['guard' => $event->guard],
                'ورود موفق به سیستم',
            ],
            $event instanceof Failed => [
                'auth.login_failed',
                null,
                ['email' => $event->credentials['email'] ?? null],
                'تلاش ناموفق ورود به سیستم',
            ],
            $event instanceof Logout => [
                'auth.logout',
                $event->user,
                ['guard' => $event->guard],
                'خروج از سیستم',
            ],
            $event instanceof PasswordReset => [
                'auth.password_reset',
                $event->user,
                [],
                'بازنشانی رمز عبور',
            ],
            $event instanceof PasswordUpdatedViaController => [
                'auth.password_updated',
                $event->user,
                [],
                'تغییر رمز عبور توسط کاربر',
            ],
            $event instanceof TwoFactorAuthenticationEnabled => [
                'auth.2fa_enabled', $event->user, [], 'فعال‌سازی ورود دو مرحله‌ای',
            ],
            $event instanceof TwoFactorAuthenticationDisabled => [
                'auth.2fa_disabled', $event->user, [], 'غیرفعال‌سازی ورود دو مرحله‌ای',
            ],
            $event instanceof TwoFactorAuthenticationConfirmed => [
                'auth.2fa_confirmed', $event->user, [], 'تأیید ورود دو مرحله‌ای',
            ],
            $event instanceof TwoFactorAuthenticationChallenged => [
                'auth.2fa_challenged', $event->user, [], 'درخواست چالش ورود دو مرحله‌ای',
            ],
            $event instanceof TwoFactorAuthenticationFailed => [
                'auth.2fa_failed', $event->user, [], 'ورود دو مرحله‌ای ناموفق',
            ],
            $event instanceof RecoveryCodeReplaced => [
                'auth.recovery_code_replaced', $event->user, [], 'مصرف کد بازیابی دو مرحله‌ای',
            ],
            $event instanceof RecoveryCodesGenerated => [
                'auth.recovery_codes_generated', $event->user, [], 'تولید کدهای بازیابی دو مرحله‌ای',
            ],
            $event instanceof PasskeyRegistered => [
                'auth.passkey_registered', $event->user, [], 'ثبت Passkey',
            ],
            $event instanceof PasskeyVerified => [
                'auth.passkey_verified', $event->user, [], 'ورود با Passkey',
            ],
            $event instanceof PasskeyDeleted => [
                'auth.passkey_deleted', $event->user, [], 'حذف Passkey',
            ],
            default => [null, null, [], null],
        };

        if ($name === null) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();
        $user = $user instanceof User ? $user : null;

        $log = AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'event' => $name,
            'auditable_type' => User::class,
            'auditable_id' => $user?->getKey() ?? 0,
            'description' => $description,
            'new_values' => $values === [] ? null : $values,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        if (in_array($name, ['auth.login_failed', 'auth.2fa_failed', 'auth.password_reset'], true)) {
            Log::warning('Security audit alert', ['event' => $name, 'audit_log_id' => $log->id]);
        }
    }
}
