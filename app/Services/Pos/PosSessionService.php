<?php

namespace App\Services\Pos;

use App\Enums\TreasuryAccountType;
use App\Models\Branch;
use App\Models\PosSession;
use App\Models\PosTender;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PosSessionService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function open(Branch $branch, User $user, int $warehouseId, int $cashAccountId, ?int $cardAccountId, string $openingCash): PosSession
    {
        if (! $user->canManageSales()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($branch, $user, $warehouseId, $cashAccountId, $cardAccountId, $openingCash): PosSession {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (PosSession::query()->where('active_user_id', $user->id)->lockForUpdate()->first()) {
                throw new DomainException('شما یک شیفت باز دارید؛ همان شیفت را ادامه دهید.');
            }
            $warehouse = Warehouse::query()->whereKey($warehouseId)->lockForUpdate()->firstOrFail();
            if (! $warehouse->is_active || (int) $warehouse->branch_id !== (int) $branch->id) {
                throw new DomainException('انبار فعال این فروشگاه را انتخاب کنید.');
            }
            $cash = $this->account($cashAccountId, $branch, TreasuryAccountType::Cash);
            if (PosSession::query()->where('active_cash_account_id', $cash->id)->lockForUpdate()->first()) {
                throw new DomainException('این صندوق نقدی در یک شیفت باز دیگر استفاده می‌شود.');
            }
            if ($cardAccountId !== null) {
                $this->account($cardAccountId, $branch, TreasuryAccountType::Card);
            }
            if (Decimal::compare($openingCash, '0', 2) < 0) {
                throw new DomainException('وجه ابتدای شیفت نمی‌تواند منفی باشد.');
            }
            $session = PosSession::query()->create([
                'store_id' => $branch->store_id,
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'warehouse_id' => $warehouse->id,
                'cash_account_id' => $cash->id,
                'card_account_id' => $cardAccountId,
                'active_user_id' => $user->id,
                'active_cash_account_id' => $cash->id,
                'opening_cash' => Decimal::normalize($openingCash, 2),
                'opened_at' => now(),
            ]);
            $this->audit->record('pos.opened', $session);

            return $session;
        }, attempts: 3);
    }

    public function expectedCash(PosSession $session, bool $lockForUpdate = false): string
    {
        return PosTender::query()->select('pos_tenders.*')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_tenders.pos_sale_id')
            ->where('pos_sales.pos_session_id', $session->id)->whereNotNull('pos_sales.completed_at')
            ->where('pos_tenders.method', 'cash')->whereNotNull('pos_tenders.receipt_id')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->get()
            ->reduce(fn (string $sum, PosTender $tender): string => Decimal::add($sum, $tender->amount, 2), $session->opening_cash);
    }

    public function close(PosSession $session, User $user, string $countedCash, ?string $notes): PosSession
    {
        if ((int) $session->user_id !== (int) $user->id || ! $user->canManageSales()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($session, $countedCash, $notes): PosSession {
            $session = PosSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->closed_at !== null) {
                return $session;
            }
            $expected = $this->expectedCash($session, true);
            $difference = Decimal::subtract($countedCash, $expected, 2);
            if (Decimal::compare($countedCash, '0', 2) < 0 || (Decimal::compare($difference, '0', 2) !== 0 && trim($notes ?? '') === '')) {
                throw new DomainException('برای مغایرت وجه صندوق توضیح بنویسید.');
            }
            $session->update([
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'cash_difference' => $difference,
                'closing_notes' => $notes,
                'closed_at' => now(),
                'active_user_id' => null,
                'active_cash_account_id' => null,
            ]);
            $this->audit->record('pos.closed', $session, newValues: ['expected_cash' => $expected, 'counted_cash' => $countedCash, 'difference' => $difference]);

            return $session;
        }, attempts: 3);
    }

    private function account(int $id, Branch $branch, TreasuryAccountType $type): TreasuryAccount
    {
        $account = TreasuryAccount::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $account->is_active || $account->type !== $type || (int) $account->branch_id !== (int) $branch->id) {
            throw new DomainException('حساب خزانه با نوع پرداخت یا فروشگاه سازگار نیست.');
        }

        return $account;
    }
}
