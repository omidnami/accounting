<?php

namespace App\Services\Treasury;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Store;
use App\Models\TreasuryAccount;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TreasuryAccountService
{
    /**
     * @param  array{
     *     code: string, name: string, type: string, gl_account_id: int,
     *     is_default: bool, is_active: bool, version: int
     * }  $data
     */
    public function create(Store $store, Branch $branch, array $data): TreasuryAccount
    {
        try {
            return DB::transaction(function () use ($store, $branch, $data): TreasuryAccount {
                $this->assertBranch($store, $branch);
                $type = TreasuryAccountType::from($data['type']);
                $glAccount = $this->glAccount(
                    $store,
                    $type,
                    (int) $data['gl_account_id'],
                    true,
                );
                $isActive = (bool) $data['is_active'];
                $isDefault = $isActive && (bool) $data['is_default'];

                if ($isDefault) {
                    $this->clearOtherDefaults($branch, $type);
                }

                return TreasuryAccount::query()->create([
                    'store_id' => $store->id,
                    'branch_id' => $branch->id,
                    'gl_account_id' => $glAccount->id,
                    'code' => strtoupper(trim($data['code'])),
                    'name' => trim($data['name']),
                    'type' => $type,
                    'is_default' => $isDefault,
                    'is_active' => $isActive,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->constraintException($exception);
        }
    }

    /**
     * @param  array{
     *     code: string, name: string, type: string, gl_account_id: int,
     *     is_default: bool, is_active: bool, version: int
     * }  $data
     */
    public function update(TreasuryAccount $treasury, array $data): TreasuryAccount
    {
        try {
            return DB::transaction(function () use ($treasury, $data): TreasuryAccount {
                $locked = TreasuryAccount::query()
                    ->whereKey($treasury->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $store = Store::query()->findOrFail($locked->store_id);
                $branch = Branch::query()->findOrFail($locked->branch_id);
                $this->assertBranch($store, $branch);

                if ($locked->version !== (int) $data['version']) {
                    throw new DomainException('این حساب هم‌زمان توسط کاربر دیگری تغییر کرده است. اطلاعات تازه را دوباره بررسی کنید.');
                }

                $type = TreasuryAccountType::from($data['type']);
                $isActive = (bool) $data['is_active'];
                $glAccount = $this->glAccount(
                    $store,
                    $type,
                    (int) $data['gl_account_id'],
                    $isActive || $locked->gl_account_id !== (int) $data['gl_account_id'],
                );
                $code = strtoupper(trim($data['code']));
                $hasUsage = $locked->receipts()->exists()
                    || $locked->payments()->exists()
                    || $locked->settledChecks()->exists();

                if ($hasUsage
                    && ($locked->type !== $type
                        || $locked->gl_account_id !== $glAccount->id
                        || $locked->code !== $code)) {
                    throw new DomainException('کد، نوع و حساب دفتر کل حساب دارای گردش مالی قابل تغییر نیست.');
                }

                if (! $isActive) {
                    $this->assertCanDeactivate($locked);
                }

                $isDefault = $isActive && (bool) $data['is_default'];

                if ($isDefault) {
                    $this->clearOtherDefaults($branch, $type, $locked->id);
                }

                $locked->update([
                    'gl_account_id' => $glAccount->id,
                    'code' => $code,
                    'name' => trim($data['name']),
                    'type' => $type,
                    'is_default' => $isDefault,
                    'is_active' => $isActive,
                    'version' => $locked->version + 1,
                ]);

                return $locked->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->constraintException($exception);
        }
    }

    public function setActive(
        TreasuryAccount $treasury,
        bool $isActive,
        int $version,
    ): TreasuryAccount {
        return DB::transaction(function () use ($treasury, $isActive, $version): TreasuryAccount {
            $locked = TreasuryAccount::query()
                ->whereKey($treasury->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->version !== $version) {
                throw new DomainException('این حساب هم‌زمان توسط کاربر دیگری تغییر کرده است. اطلاعات تازه را دوباره بررسی کنید.');
            }

            if (! $isActive) {
                $this->assertCanDeactivate($locked);
            }

            $locked->update([
                'is_active' => $isActive,
                'is_default' => $isActive ? $locked->is_default : false,
                'version' => $locked->version + 1,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function assertBranch(Store $store, Branch $branch): void
    {
        if ((int) $branch->store_id !== (int) $store->id || ! $branch->is_active) {
            throw new DomainException('شعبه حساب خزانه باید فعال و متعلق به فروشگاه باشد.');
        }
    }

    private function glAccount(
        Store $store,
        TreasuryAccountType $type,
        int $glAccountId,
        bool $requireActive,
    ): Account {
        $allowedCode = config(
            "accounting.treasury_account_gl_codes.{$type->value}",
        );
        $account = Account::query()
            ->whereKey($glAccountId)
            ->where('store_id', $store->id)
            ->where('level', AccountLevel::Subsidiary)
            ->where('type', AccountType::Asset)
            ->when($requireActive, fn ($query) => $query->where('is_active', true))
            ->when(
                is_string($allowedCode),
                fn ($query) => $query->where('code', $allowedCode),
            )
            ->first();

        if (! $account) {
            throw new DomainException('حساب دفتر کل باید معین، دارایی، مجاز برای این نوع و در صورت فعال‌بودن حساب خزانه، فعال باشد.');
        }

        return $account;
    }

    private function clearOtherDefaults(
        Branch $branch,
        TreasuryAccountType $type,
        ?int $exceptId = null,
    ): void {
        TreasuryAccount::query()
            ->where('branch_id', $branch->id)
            ->where('type', $type)
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update([
                'is_default' => false,
                'default_slot' => null,
            ]);
    }

    private function assertCanDeactivate(TreasuryAccount $treasury): void
    {
        $hasPendingDocuments = $treasury->receipts()
            ->where('status', TreasuryDocumentStatus::Pending)
            ->exists()
            || $treasury->payments()
                ->where('status', TreasuryDocumentStatus::Pending)
                ->exists();

        if ($hasPendingDocuments) {
            throw new DomainException('حساب دارای دریافت یا پرداخت در انتظار تأیید قابل غیرفعال‌سازی نیست.');
        }
    }

    private function constraintException(
        UniqueConstraintViolationException $exception,
    ): DomainException {
        if (str_contains($exception->getMessage(), 'treasury_accounts_branch_default_unique')) {
            return new DomainException('برای این نوع حساب در شعبه فقط یک حساب می‌تواند پیش‌فرض باشد.');
        }

        return new DomainException('این کد حساب خزانه در شعبه جاری قبلاً استفاده شده است.');
    }
}
