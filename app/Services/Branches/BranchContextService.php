<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use DomainException;

class BranchContextService
{
    public function active(User $user, Store $store): Branch
    {
        $query = Branch::query()
            ->where('store_id', $store->id)
            ->where('is_active', true);

        if ($user->branch_id !== null) {
            $query->whereKey($user->branch_id);
        } else {
            $query->where('is_default', true);
        }

        return $query->first()
            ?? throw new DomainException('شعبهٔ فعالی برای این کاربر وجود ندارد.');
    }

    /**
     * Multi-branch switching is intentionally unavailable in the current
     * single-location release. Routes are also rejected by middleware.
     */
    public function switch(User $user, Branch $branch): void
    {
        throw new DomainException('مدیریت چند شعبه در نسخهٔ فعلی غیرفعال است.');
    }
}
