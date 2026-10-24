<?php

namespace App\Services\Branches;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class BranchContextService
{
    private const SESSION_KEY = 'active_branch_id';

    /**
     * @return Collection<int, Branch>
     */
    public function available(User $user, Store $store): Collection
    {
        $query = Branch::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $this->canSwitch($user)) {
            if ($user->branch_id !== null) {
                $query->whereKey($user->branch_id);
            } else {
                $query->where('is_default', true);
            }
        }

        return $query->get(['id', 'store_id', 'code', 'name', 'is_default', 'is_active']);
    }

    public function active(User $user, Store $store): Branch
    {
        $branches = $this->available($user, $store);

        if ($this->canSwitch($user)) {
            $requested = session(self::SESSION_KEY);
            $branch = $requested !== null
                ? $branches->firstWhere('id', (int) $requested)
                : null;

            if ($branch !== null) {
                return $branch;
            }
        }

        return $branches->first()
            ?? throw new DomainException('شعبهٔ فعالی برای این کاربر وجود ندارد.');
    }

    public function switch(User $user, Branch $branch): void
    {
        if (! $this->canSwitch($user)) {
            throw new DomainException('تغییر شعبهٔ فعال برای نقش شما مجاز نیست.');
        }

        if (! $branch->is_active) {
            throw new DomainException('شعبهٔ غیرفعال را نمی‌توان انتخاب کرد.');
        }

        $store = $branch->store;

        if ($store === null
            || ! $this->available($user, $store)->contains('id', $branch->id)) {
            throw new DomainException('دسترسی به این شعبه مجاز نیست.');
        }

        session([self::SESSION_KEY => $branch->id]);
    }

    /**
     * @return array{
     *     active: array{id: int, name: string, code: string},
     *     branches: array<int, array{id: int, name: string, code: string}>,
     *     can_switch: bool
     * }
     */
    public function shared(User $user, Store $store): array
    {
        $branches = $this->available($user, $store);
        $active = $this->active($user, $store);

        return [
            'active' => [
                'id' => $active->id,
                'name' => $active->name,
                'code' => $active->code,
            ],
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ])->values()->all(),
            'can_switch' => $this->canSwitch($user),
        ];
    }

    private function canSwitch(User $user): bool
    {
        $role = $user->getAttribute('role');

        return $role instanceof UserRole && $role->canSwitchBranches();
    }
}
