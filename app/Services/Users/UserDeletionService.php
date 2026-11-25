<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Security\SessionSecurityService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserDeletionService
{
    public function __construct(
        private readonly SessionSecurityService $sessions,
    ) {}

    public function delete(User $target): void
    {
        DB::transaction(function () use ($target): void {
            $managers = User::query()
                ->where('role', UserRole::Manager)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'role']);
            $locked = User::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->role === UserRole::Manager && $managers->count() <= 1) {
                throw new DomainException('آخرین مدیر سیستم قابل حذف نیست.');
            }

            $locked->forceFill([
                'name' => 'کاربر حذف‌شده',
                'email' => 'deleted-'.$locked->id.'@anonymous.local',
                'password' => Str::random(64),
                'branch_id' => null,
                'is_active' => false,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $this->sessions->revokeAllSessions($locked);
        }, attempts: 3);
    }
}
