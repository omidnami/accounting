<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    public function delete(User $target): void
    {
        DB::transaction(function () use ($target): void {
            $managers = User::query()
                ->where('role', UserRole::Manager)
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

            $locked->delete();
        }, attempts: 3);
    }
}
