<?php

namespace App\Services\Setup;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FirstRunSetupService
{
    /**
     * @param  array{
     *     store_name: string, admin_name: string, email: string,
     *     password: string, timezone: string, currency: string
     * }  $data
     */
    public function setup(array $data): User
    {
        return Cache::lock('first-run-setup', 30)->block(5, function () use ($data): User {
            if (User::query()->exists()) {
                throw new DomainException('راه‌اندازی اولیه قبلاً انجام شده است.');
            }

            return DB::transaction(function () use ($data): User {
                $store = Store::query()->firstOrNew(['code' => 'MAIN']);
                $store->fill([
                    'name' => trim($data['store_name']),
                    'currency' => $data['currency'],
                    'timezone' => $data['timezone'],
                    'is_active' => true,
                ])->save();
                $branch = Branch::query()->firstOrCreate(
                    ['store_id' => $store->id, 'code' => 'MAIN'],
                    [
                        'name' => 'شعبه اصلی',
                        'is_default' => true,
                        'is_active' => true,
                    ],
                );
                Warehouse::query()->firstOrCreate(
                    ['store_id' => $store->id, 'branch_id' => $branch->id, 'code' => 'MAIN'],
                    [
                        'name' => 'انبار اصلی',
                        'is_default' => true,
                        'is_active' => true,
                    ],
                );
                app(AccountSeeder::class)->run();
                app(AccountingSetupSeeder::class)->run();
                $user = new User;
                $user->forceFill([
                    'name' => trim($data['admin_name']),
                    'email' => strtolower(trim($data['email'])),
                    'password' => $data['password'],
                    'role' => UserRole::Manager,
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'version' => 0,
                ])->save();

                return $user->refresh();
            });
        });
    }
}
