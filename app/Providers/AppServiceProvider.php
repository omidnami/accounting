<?php

namespace App\Providers;

use App\Listeners\SecurityAuditListener;
use App\Observers\AuditObserver;
use App\Services\Accounting\CorePeriodCloseBlocker;
use App\Services\Accounting\PeriodCloseBlockerRegistry;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerAuditObservers();
        $this->registerSecurityAuditListener();
        $this->registerPeriodCloseBlockers();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function registerPeriodCloseBlockers(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(PeriodCloseBlockerRegistry::class);
        app(PeriodCloseBlockerRegistry::class)->register(app(CorePeriodCloseBlocker::class));
    }

    private function registerSecurityAuditListener(): void
    {
        Event::listen([
            Login::class,
            Failed::class,
            Logout::class,
            PasswordReset::class,
            PasswordUpdatedViaController::class,
            RecoveryCodeReplaced::class,
            RecoveryCodesGenerated::class,
            TwoFactorAuthenticationChallenged::class,
            TwoFactorAuthenticationConfirmed::class,
            TwoFactorAuthenticationDisabled::class,
            TwoFactorAuthenticationEnabled::class,
            TwoFactorAuthenticationFailed::class,
            PasskeyDeleted::class,
            PasskeyRegistered::class,
            PasskeyVerified::class,
        ], SecurityAuditListener::class);
    }

    private function registerAuditObservers(): void
    {
        foreach (config('audit.models', []) as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
