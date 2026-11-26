<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Setup\FirstRunSetupService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @return array<string, mixed>
 */
function firstRunServicePayload(array $overrides = []): array
{
    return array_replace([
        'store_name' => ' فروشگاه راه‌اندازی مستقیم ',
        'admin_name' => ' مدیر اول ',
        'email' => ' Owner@Example.COM ',
        'password' => 'Secure-Pass-123',
        'timezone' => 'Asia/Tehran',
        'currency' => 'IRR',
    ], $overrides);
}

it('sets up the first manager and accounting foundation directly and only once', function () {
    $user = app(FirstRunSetupService::class)->setup(firstRunServicePayload());
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $counts = [
        'users' => User::query()->count(),
        'stores' => Store::query()->count(),
        'branches' => Branch::query()->count(),
        'warehouses' => Warehouse::query()->count(),
        'fiscal_years' => FiscalYear::query()->count(),
        'accounts' => Account::query()->count(),
        'treasury_accounts' => TreasuryAccount::query()->count(),
    ];

    expect($user->name)->toBe('مدیر اول')
        ->and($user->email)->toBe('owner@example.com')
        ->and($user->role)->toBe(UserRole::Manager)
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->version)->toBe(0)
        ->and(Hash::check('Secure-Pass-123', $user->password))->toBeTrue()
        ->and($store->name)->toBe('فروشگاه راه‌اندازی مستقیم')
        ->and($store->currency)->toBe('IRR')
        ->and($store->timezone)->toBe('Asia/Tehran')
        ->and($store->defaultBranch->is_default)->toBeTrue()
        ->and($store->defaultBranch->warehouses()->where('code', 'MAIN')->count())->toBe(1)
        ->and($counts)->toBe([
            'users' => 1,
            'stores' => 1,
            'branches' => 1,
            'warehouses' => 1,
            'fiscal_years' => 1,
            'accounts' => 31,
            'treasury_accounts' => 3,
        ])
        ->and(Auth::check())->toBeFalse();

    expect(fn () => app(FirstRunSetupService::class)->setup(firstRunServicePayload([
        'email' => 'second@example.com',
    ])))->toThrow(DomainException::class, 'راه‌اندازی اولیه قبلاً انجام شده است.');

    expect(User::query()->count())->toBe(1)
        ->and(Store::query()->count())->toBe(1)
        ->and(Account::query()->count())->toBe(31);
});

it('rolls back the entire first run setup when manager creation fails', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The forced manager failure uses a SQLite trigger.');
    }

    $storeBefore = Store::query()->where('code', 'MAIN')->firstOrFail()
        ->only(['name', 'currency', 'timezone', 'is_active']);
    $before = [
        'users' => User::query()->count(),
        'stores' => Store::query()->count(),
        'branches' => Branch::query()->count(),
        'warehouses' => Warehouse::query()->count(),
        'fiscal_years' => FiscalYear::query()->count(),
        'accounts' => Account::query()->count(),
        'treasury_accounts' => TreasuryAccount::query()->count(),
        'audit_logs' => AuditLog::query()->count(),
    ];
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER first_run_service_abort_user
        BEFORE INSERT ON users
        BEGIN
            SELECT RAISE(ABORT, 'forced first-run user failure');
        END
        SQL);

    try {
        expect(fn () => app(FirstRunSetupService::class)->setup(firstRunServicePayload()))
            ->toThrow(QueryException::class, 'forced first-run user failure');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS first_run_service_abort_user');
    }

    $after = [
        'users' => User::query()->count(),
        'stores' => Store::query()->count(),
        'branches' => Branch::query()->count(),
        'warehouses' => Warehouse::query()->count(),
        'fiscal_years' => FiscalYear::query()->count(),
        'accounts' => Account::query()->count(),
        'treasury_accounts' => TreasuryAccount::query()->count(),
        'audit_logs' => AuditLog::query()->count(),
    ];

    expect($after)->toBe($before)
        ->and(Store::query()->where('code', 'MAIN')->firstOrFail()
            ->only(['name', 'currency', 'timezone', 'is_active']))->toBe($storeBefore)
        ->and($after['users'])->toBe(0)
        ->and($after['accounts'])->toBe(0)
        ->and($after['audit_logs'])->toBe(0);
});
