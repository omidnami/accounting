<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use DomainException;

class BranchContextService
{
    public function active(User $_user, Store $store): Branch
    {
        return Branch::query()
            ->where('store_id', $store->id)
            ->where('is_default', true)
            ->first()
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
