<?php

namespace App\Services\Accounting;

use App\Enums\AccountLevel;
use App\Models\Account;
use DomainException;
use InvalidArgumentException;

class SettingService
{
    public function defaultAccountCode(string $key): string
    {
        $code = config("accounting.default_accounts.{$key}");

        if (! is_string($code) || $code === '') {
            throw new InvalidArgumentException("نگاشت حساب پیش‌فرض [{$key}] تعریف نشده است.");
        }

        return $code;
    }

    public function defaultAccount(string $key, ?int $storeId = null): Account
    {
        $query = Account::query()
            ->where('code', $this->defaultAccountCode($key))
            ->where('is_active', true);

        if ($storeId !== null) {
            $query->where(function ($query) use ($storeId): void {
                $query->where('store_id', $storeId)
                    ->orWhereNull('store_id');
            })->orderByRaw('store_id IS NULL');
        }

        $account = $query->first();

        if (! $account) {
            throw new DomainException("حساب پیش‌فرض فعال برای کلید [{$key}] پیدا نشد.");
        }

        if ($account->level !== AccountLevel::Subsidiary) {
            throw new DomainException("حساب پیش‌فرض [{$key}] باید از سطح معین باشد.");
        }

        return $account;
    }

    public function defaultAccountId(string $key, ?int $storeId = null): int
    {
        return $this->defaultAccount($key, $storeId)->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function mappings(): array
    {
        return config('accounting.default_accounts', []);
    }
}
