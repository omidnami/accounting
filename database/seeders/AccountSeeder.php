<?php

namespace Database\Seeders;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(StoreSeeder::class);

        $store = Store::query()->where('code', 'MAIN')->firstOrFail();

        $accounts = [
            [
                'code' => '1000',
                'name' => 'دارایی‌ها',
                'type' => AccountType::Asset,
                'level' => AccountLevel::General,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '1101',
                'name' => 'صندوق',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '1102',
                'name' => 'بانک',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '1103',
                'name' => 'حساب‌های دریافتنی',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
                'requires_party' => true,
            ],
            [
                'code' => '1104',
                'name' => 'موجودی کالا',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '1105',
                'name' => 'پیش‌پرداخت‌ها',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '1106',
                'name' => 'مالیات بر ارزش افزوده قابل بازیافت',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '1201',
                'name' => 'دارایی‌های ثابت',
                'parent_code' => '1000',
                'type' => AccountType::Asset,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '2000',
                'name' => 'بدهی‌ها',
                'type' => AccountType::Liability,
                'level' => AccountLevel::General,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '2101',
                'name' => 'حساب‌های پرداختنی',
                'parent_code' => '2000',
                'type' => AccountType::Liability,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
                'requires_party' => true,
            ],
            [
                'code' => '2102',
                'name' => 'مالیات بر ارزش افزوده پرداختنی',
                'parent_code' => '2000',
                'type' => AccountType::Liability,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '2103',
                'name' => 'چک‌های پرداختنی',
                'parent_code' => '2000',
                'type' => AccountType::Liability,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '2104',
                'name' => 'هزینه‌های تعهدی',
                'parent_code' => '2000',
                'type' => AccountType::Liability,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '3000',
                'name' => 'سرمایه',
                'type' => AccountType::Equity,
                'level' => AccountLevel::General,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '3101',
                'name' => 'سرمایه مالک',
                'parent_code' => '3000',
                'type' => AccountType::Equity,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '3102',
                'name' => 'سود و زیان انباشته',
                'parent_code' => '3000',
                'type' => AccountType::Equity,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '4000',
                'name' => 'درآمدها',
                'type' => AccountType::Revenue,
                'level' => AccountLevel::General,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '4101',
                'name' => 'فروش کالا',
                'parent_code' => '4000',
                'type' => AccountType::Revenue,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Credit,
            ],
            [
                'code' => '4102',
                'name' => 'برگشت از فروش',
                'parent_code' => '4000',
                'type' => AccountType::Revenue,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '4103',
                'name' => 'تخفیفات فروش',
                'parent_code' => '4000',
                'type' => AccountType::Revenue,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '5000',
                'name' => 'هزینه‌ها',
                'type' => AccountType::Expense,
                'level' => AccountLevel::General,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '5101',
                'name' => 'بهای تمام‌شده کالای فروش‌رفته',
                'parent_code' => '5000',
                'type' => AccountType::Expense,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '5102',
                'name' => 'هزینه اجاره',
                'parent_code' => '5000',
                'type' => AccountType::Expense,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '5103',
                'name' => 'هزینه حقوق و دستمزد',
                'parent_code' => '5000',
                'type' => AccountType::Expense,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '5104',
                'name' => 'هزینه آب، برق و گاز',
                'parent_code' => '5000',
                'type' => AccountType::Expense,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
            [
                'code' => '5105',
                'name' => 'سایر هزینه‌های عملیاتی',
                'parent_code' => '5000',
                'type' => AccountType::Expense,
                'level' => AccountLevel::Subsidiary,
                'normal_balance' => NormalBalance::Debit,
            ],
        ];

        DB::transaction(function () use ($accounts, $store): void {
            foreach ($accounts as $account) {
                $parentId = isset($account['parent_code'])
                    ? Account::query()
                        ->where('store_id', $store->id)
                        ->where('code', $account['parent_code'])
                        ->value('id')
                    : null;

                Account::query()->firstOrCreate(
                    [
                        'store_id' => $store->id,
                        'code' => $account['code'],
                    ],
                    [
                        'parent_id' => $parentId,
                        'name' => $account['name'],
                        'type' => $account['type'],
                        'level' => $account['level'],
                        'normal_balance' => $account['normal_balance'],
                        'requires_party' => $account['requires_party'] ?? false,
                        'is_active' => true,
                    ],
                );
            }
        });
    }
}
