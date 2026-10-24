<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
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
        try {
            return DB::transaction(function () use ($data): User {
                $user = new User;
                $user->name = trim($data['name']);
                $user->email = strtolower(trim($data['email']));
                $user->password = $data['password'];
                $user->email_verified_at = now();
                $user->role = UserRole::from($data['role']);
                $user->branch_id = null;
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
     *     password?: string|null, version: int
     * }  $data
     */
    public function update(User $target, array $data, User $actor): User
    {
        try {
            return DB::transaction(function () use ($target, $data, $actor): User {
                $managers = User::query()
                    ->where('role', UserRole::Manager)
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

                if ((int) $locked->id === (int) $actor->id && $locked->role !== $newRole) {
                    throw new DomainException('نقش حساب کاربری خودتان را نمی‌توانید تغییر دهید.');
                }

                if ($locked->role === UserRole::Manager
                    && $newRole !== UserRole::Manager
                    && $managers->count() <= 1) {
                    throw new DomainException('آخرین مدیر سیستم را نمی‌توان به نقش دیگری تغییر داد.');
                }

                $locked->name = trim($data['name']);
                $locked->email = strtolower(trim($data['email']));
                $locked->role = $newRole;
                $locked->branch_id = null;

                if (isset($data['password']) && $data['password'] !== '') {
                    $locked->password = $data['password'];
                }

                $locked->save();

                return $locked->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('این ایمیل قبلاً برای کاربر دیگری ثبت شده است.');
        }
    }
}
