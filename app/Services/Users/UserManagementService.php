<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\PosSession;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Security\SessionSecurityService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UserManagementService
{
    public function __construct(
        private readonly SessionSecurityService $sessions,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array{
     *     name: string, email: string, role: string,
     *     password: string, version: int, is_active?: bool
     * }  $data
     */
    public function create(array $data): User
    {
        try {
            return DB::transaction(function () use ($data): User {
                $user = new User;
                $user->name = trim($data['name']);
                $user->email = strtolower(trim($data['email']));
                $user->password = $data['password'];
                $user->email_verified_at = now();
                $user->role = UserRole::from($data['role']);
                $user->branch_id = null;
                $user->is_active = true;
                $user->version = 0;
                $user->save();

                return $user->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('این ایمیل قبلاً برای کاربر دیگری ثبت شده است.');
        }
    }

    /**
     * @param  array{
     *     name: string, email: string, role: string,
     *     password?: string|null, version: int,
     *     is_active?: bool, reset_two_factor?: bool
     * }  $data
     */
    public function update(
        User $target,
        array $data,
        User $actor,
        ?string $keepSessionId = null,
    ): User {
        try {
            return DB::transaction(function () use ($target, $data, $actor, $keepSessionId): User {
                $managers = User::query()
                    ->where('role', UserRole::Manager)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
                $locked = User::query()
                    ->whereKey($target->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $newRole = UserRole::from($data['role']);

                if ((int) $locked->version !== (int) $data['version']) {
                    throw new DomainException('این کاربر هم‌زمان توسط مدیر دیگری تغییر کرده است.');
                }

                $isActive = array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : $locked->is_active;
                $resetTwoFactor = (bool) ($data['reset_two_factor'] ?? false);

                if ((int) $locked->id === (int) $actor->id && $locked->role !== $newRole) {
                    throw new DomainException('نقش حساب کاربری خودتان را نمی‌توانید تغییر دهید.');
                }

                if ((int) $locked->id === (int) $actor->id && ! $isActive) {
                    throw new DomainException('حساب کاربری خودتان را نمی‌توانید غیرفعال کنید.');
                }

                if ($locked->role === UserRole::Manager
                    && (! $isActive || $newRole !== UserRole::Manager)
                    && $managers->count() <= 1) {
                    throw new DomainException('آخرین مدیر فعال سیستم را نمی‌توان غیرفعال یا به نقش دیگری تغییر داد.');
                }

                if ($locked->is_active && ! $isActive
                    && PosSession::query()
                        ->where('user_id', $locked->id)
                        ->whereNull('closed_at')
                        ->exists()) {
                    throw new DomainException('ابتدا شیفت باز صندوق این کاربر را ببندید.');
                }

                $locked->name = trim($data['name']);
                $locked->email = strtolower(trim($data['email']));
                $locked->role = $newRole;
                $locked->branch_id = null;
                $locked->is_active = $isActive;
                $passwordChanged = isset($data['password']) && $data['password'] !== '';

                if ($passwordChanged) {
                    $locked->password = $data['password'];
                }

                if ($resetTwoFactor) {
                    $locked->forceFill([
                        'two_factor_secret' => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at' => null,
                    ]);
                }

                $locked->save();

                if ($locked->is_active === false) {
                    $this->sessions->revokeAllSessions($locked);
                } elseif ($passwordChanged || $resetTwoFactor) {
                    $this->sessions->revokeOtherSessions($locked, $keepSessionId);
                }

                if ($resetTwoFactor) {
                    $this->audit->record(
                        'two_factor_reset',
                        $locked,
                        description: 'بازنشانی دو مرحله‌ای توسط مدیر',
                    );
                }

                return $locked->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('این ایمیل قبلاً برای کاربر دیگری ثبت شده است.');
        }
    }
}
