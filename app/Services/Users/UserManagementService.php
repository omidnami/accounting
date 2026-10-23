<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class UserManagementService
{
    /**
     * @param  array{
     *     name: string, email: string, role: string,
     *     password: string, version: int
     * }  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User;
            $user->name = trim($data['name']);
            $user->email = strtolower(trim($data['email']));
            $user->password = $data['password'];
            $user->email_verified_at = now();
            $user->role = UserRole::from($data['role']);
            $user->version = 0;
            $user->save();

            return $user->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array{
     *     name: string, email: string, role: string,
     *     password?: string|null, version: int
     * }  $data
     */
    public function update(User $target, array $data, User $actor): User
    {
        return DB::transaction(function () use ($target, $data, $actor): User {
            $locked = User::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();
            $newRole = UserRole::from($data['role']);

            if ((int) $locked->version !== (int) $data['version']) {
                throw new DomainException('این کاربر هم‌زمان توسط مدیر دیگری تغییر کرده است.');
            }

            if ((int) $locked->id === (int) $actor->id && $locked->role !== $newRole) {
                throw new DomainException('نقش حساب کاربری خودتان را نمی‌توانید تغییر دهید.');
            }

            if ($locked->role === UserRole::Manager && $newRole !== UserRole::Manager) {
                $managerCount = User::query()
                    ->where('role', UserRole::Manager)
                    ->lockForUpdate()
                    ->count();

                if ($managerCount <= 1) {
                    throw new DomainException('آخرین مدیر سیستم را نمی‌توان به نقش دیگری تغییر داد.');
                }
            }

            $locked->name = trim($data['name']);
            $locked->email = strtolower(trim($data['email']));
            $locked->role = $newRole;
            $locked->version++;

            if (isset($data['password']) && $data['password'] !== '') {
                $locked->password = $data['password'];
            }

            $locked->save();

            return $locked->refresh();
        }, attempts: 3);
    }
}
