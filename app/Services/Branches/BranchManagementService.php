<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\Store;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BranchManagementService
{
    /**
     * @param  array{code: string, name: string, phone: string|null, address: string|null}  $data
     */
    public function create(Store $store, array $data): Branch
    {
        try {
            return DB::transaction(function () use ($store, $data): Branch {
                $branch = Branch::query()->create([
                    'store_id' => $store->id,
                    'code' => mb_strtoupper(trim($data['code']), 'UTF-8'),
                    'name' => trim($data['name']),
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'is_default' => false,
                    'is_active' => true,
                ]);

                Warehouse::query()->create([
                    'store_id' => $store->id,
                    'branch_id' => $branch->id,
                    'code' => 'MAIN',
                    'name' => 'انبار اصلی',
                    'is_default' => true,
                    'is_active' => true,
                ]);

                return $branch->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('کد شعبه در این فروشگاه قبلاً استفاده شده است.');
        }
    }

    /**
     * @param  array{code: string, name: string, phone: string|null, address: string|null}  $data
     */
    public function update(Branch $branch, array $data): Branch
    {
        try {
            $branch->update([
                'code' => mb_strtoupper(trim($data['code']), 'UTF-8'),
                'name' => trim($data['name']),
                'phone' => $data['phone'],
                'address' => $data['address'],
            ]);

            return $branch->refresh();
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('کد شعبه در این فروشگاه قبلاً استفاده شده است.');
        }
    }

    public function setDefault(Branch $branch): void
    {
        if (! $branch->is_active) {
            throw new DomainException('شعبهٔ غیرفعال را نمی‌توان شعبهٔ پیش‌فرض کرد.');
        }

        DB::transaction(function () use ($branch): void {
            Branch::query()
                ->where('store_id', $branch->store_id)
                ->lockForUpdate()
                ->update(['is_default' => false]);
            Branch::query()
                ->whereKey($branch->id)
                ->update(['is_default' => true]);
        }, attempts: 3);

        session(['active_branch_id' => $branch->id]);
    }

    public function updateStatus(Branch $branch, bool $active): Branch
    {
        if ($branch->is_default && ! $active) {
            throw new DomainException('شعبهٔ پیش‌فرض را ابتدا تغییر دهید و سپس غیرفعال کنید.');
        }

        if (! $active && $branch->users()->exists()) {
            throw new DomainException('ابتدا کارمندان این شعبه را به شعبهٔ دیگری منتقل کنید.');
        }

        if (! $active && $branch->is_active) {
            $activeBranches = Branch::query()
                ->where('store_id', $branch->store_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get(['id']);

            if ($activeBranches->skip(1)->isEmpty()) {
                throw new DomainException('حداقل یک شعبهٔ فعال باید باقی بماند.');
            }
        }

        $branch->update(['is_active' => $active]);

        if (! $active && (int) session('active_branch_id') === (int) $branch->id) {
            $default = Branch::query()
                ->where('store_id', $branch->store_id)
                ->where('is_default', true)
                ->first();
            session(['active_branch_id' => $default?->id]);
        }

        return $branch->refresh();
    }
}
