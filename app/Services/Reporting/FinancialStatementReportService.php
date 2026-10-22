<?php

namespace App\Services\Reporting;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinancialStatementReportService
{
    /**
     * @return array{
     *     revenues: Collection<int, array<string, string>>,
     *     expenses: Collection<int, array<string, string>>,
     *     total_revenue: string,
     *     total_expense: string,
     *     net_profit: string
     * }
     */
    public function incomeStatement(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): array {
        $fromDate = CarbonImmutable::parse($from)->toDateString();
        $toDate = CarbonImmutable::parse($to)->toDateString();
        $accounts = $this->amountsByAccount($storeId, $fromDate, $toDate, $branchId);
        $revenues = collect();
        $expenses = collect();
        $totalRevenue = '0.00';
        $totalExpense = '0.00';

        foreach ($accounts as $row) {
            $account = $row['account'];

            if ($account->type === AccountType::Revenue) {
                $amount = Decimal::subtract($row['credit'], $row['debit'], 2);
                $totalRevenue = Decimal::add($totalRevenue, $amount, 2);

                if (! Decimal::isZero($amount)) {
                    $revenues->push($this->line($account, $amount));
                }
            }

            if ($account->type === AccountType::Expense) {
                $amount = Decimal::subtract($row['debit'], $row['credit'], 2);
                $totalExpense = Decimal::add($totalExpense, $amount, 2);

                if (! Decimal::isZero($amount)) {
                    $expenses->push($this->line($account, $amount));
                }
            }
        }

        return [
            'revenues' => $revenues,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit' => Decimal::subtract($totalRevenue, $totalExpense, 2),
        ];
    }

    /**
     * @return array{
     *     assets: Collection<int, array<string, string>>,
     *     liabilities: Collection<int, array<string, string>>,
     *     equity: Collection<int, array<string, string>>,
     *     total_assets: string,
     *     total_liabilities: string,
     *     total_equity: string,
     *     net_profit: string,
     *     balanced: bool
     * }
     */
    public function balanceSheet(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): array {
        $asOfDate = CarbonImmutable::parse($asOf)->toDateString();
        $accounts = $this->amountsByAccount($storeId, null, $asOfDate, $branchId);
        $assets = collect();
        $liabilities = collect();
        $equity = collect();
        $totals = [
            AccountType::Asset->value => '0.00',
            AccountType::Liability->value => '0.00',
            AccountType::Equity->value => '0.00',
        ];
        $revenue = '0.00';
        $expense = '0.00';

        foreach ($accounts as $row) {
            $account = $row['account'];

            $amount = match ($account->type) {
                AccountType::Asset => Decimal::subtract($row['debit'], $row['credit'], 2),
                AccountType::Liability,
                AccountType::Equity => Decimal::subtract($row['credit'], $row['debit'], 2),
                AccountType::Revenue => null,
                AccountType::Expense => null,
            };

            if ($account->type === AccountType::Revenue) {
                $revenue = Decimal::add($revenue, Decimal::subtract($row['credit'], $row['debit'], 2), 2);
            } elseif ($account->type === AccountType::Expense) {
                $expense = Decimal::add($expense, Decimal::subtract($row['debit'], $row['credit'], 2), 2);
            } elseif ($amount !== null) {
                $totals[$account->type->value] = Decimal::add($totals[$account->type->value], $amount, 2);

                if (! Decimal::isZero($amount)) {
                    if ($account->type === AccountType::Asset) {
                        $assets->push($this->line($account, $amount));
                    } elseif ($account->type === AccountType::Liability) {
                        $liabilities->push($this->line($account, $amount));
                    } elseif ($account->type === AccountType::Equity) {
                        $equity->push($this->line($account, $amount));
                    }
                }
            }
        }

        $netProfit = Decimal::subtract($revenue, $expense, 2);
        $equityTotal = Decimal::add(
            $totals[AccountType::Equity->value],
            $netProfit,
            2,
        );
        $rightSide = Decimal::add($totals[AccountType::Liability->value], $equityTotal, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totals[AccountType::Asset->value],
            'total_liabilities' => $totals[AccountType::Liability->value],
            'total_equity' => $equityTotal,
            'net_profit' => $netProfit,
            'balanced' => Decimal::compare(
                $totals[AccountType::Asset->value],
                $rightSide,
                2,
            ) === 0,
        ];
    }

    /**
     * @return Collection<int, array{account: Account, debit: numeric-string, credit: numeric-string}>
     */
    private function amountsByAccount(
        int $storeId,
        ?string $from,
        string $to,
        ?int $branchId,
    ): Collection {
        $totals = JournalEntryLine::query()
            ->select('account_id')
            ->selectRaw('SUM(debit) as debit_total, SUM(credit) as credit_total')
            ->whereHas('journalEntry', function ($query) use ($storeId, $from, $to, $branchId): void {
                $query->posted()
                    ->where('store_id', $storeId)
                    ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                    ->when($from !== null, fn ($query) => $query->where('entry_date', '>=', $from))
                    ->where('entry_date', '<=', $to);
            })
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');
        $accounts = Account::query()
            ->where('store_id', $storeId)
            ->where('level', AccountLevel::Subsidiary)
            ->whereIn('id', $totals->keys())
            ->get();

        return $accounts->map(fn (Account $account): array => [
            'account' => $account,
            'debit' => Decimal::normalize($totals[$account->id]->debit_total ?? 0, 2),
            'credit' => Decimal::normalize($totals[$account->id]->credit_total ?? 0, 2),
        ])->values();
    }

    /**
     * @return array{code: string, name: string, amount: string}
     */
    private function line(Account $account, string $amount): array
    {
        return [
            'code' => $account->code,
            'name' => $account->name,
            'amount' => $amount,
        ];
    }
}
