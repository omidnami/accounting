<?php

namespace App\Services\Reporting;

use App\Enums\AccountLevel;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class TrialBalanceReportService
{
    /** @return array<string, mixed> */
    public function report(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $accounts = Account::query()
            ->where('store_id', $storeId)
            ->orderBy('code')
            ->get();
        $opening = $this->totals($storeId, null, CarbonImmutable::parse($range->from)->subDay()->toDateString(), $branchId);
        $movements = $this->totals($storeId, $range->from, $range->to, $branchId);
        $nodes = $accounts->mapWithKeys(fn (Account $account): array => [
            $account->id => [
                'account' => $account,
                'opening_debit' => Decimal::normalize($opening[$account->id]->debit_total ?? 0, 2),
                'opening_credit' => Decimal::normalize($opening[$account->id]->credit_total ?? 0, 2),
                'debit' => Decimal::normalize($movements[$account->id]->debit_total ?? 0, 2),
                'credit' => Decimal::normalize($movements[$account->id]->credit_total ?? 0, 2),
            ],
        ])->all();

        foreach ($accounts->where('level', AccountLevel::Subsidiary) as $account) {
            if ($account->parent_id !== null && isset($nodes[$account->parent_id])) {
                foreach (['opening_debit', 'opening_credit', 'debit', 'credit'] as $key) {
                    $nodes[$account->parent_id][$key] = Decimal::add(
                        $nodes[$account->parent_id][$key],
                        $nodes[$account->id][$key],
                        2,
                    );
                }
            }
        }

        $rows = collect($nodes)->map(function (array $node): array {
            /** @var Account $account */
            $account = $node['account'];
            $openingNet = Decimal::subtract($node['opening_debit'], $node['opening_credit'], 2);
            $closingNet = Decimal::add($openingNet, $node['debit'], 2);
            $closingNet = Decimal::subtract($closingNet, $node['credit'], 2);

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'level' => $account->level->value,
                'opening_debit' => $this->debitSide($openingNet),
                'opening_credit' => $this->creditSide($openingNet),
                'debit' => $node['debit'],
                'credit' => $node['credit'],
                'closing_debit' => $this->debitSide($closingNet),
                'closing_credit' => $this->creditSide($closingNet),
            ];
        })->values();
        $topLevel = $rows->where('level', AccountLevel::General->value);
        $sum = fn (string $column): string => $topLevel->reduce(
            fn (string $carry, array $row): string => Decimal::add($carry, $row[$column], 2),
            '0.00',
        );
        $openingDebit = $sum('opening_debit');
        $openingCredit = $sum('opening_credit');
        $debit = $sum('debit');
        $credit = $sum('credit');
        $closingDebit = $sum('closing_debit');
        $closingCredit = $sum('closing_credit');

        return [
            'rows' => $rows,
            'total_opening_debit' => $openingDebit,
            'total_opening_credit' => $openingCredit,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'total_closing_debit' => $closingDebit,
            'total_closing_credit' => $closingCredit,
            'balanced' => Decimal::compare($debit, $credit, 2) === 0
                && Decimal::compare($closingDebit, $closingCredit, 2) === 0,
        ];
    }

    /** @return Collection<int, JournalEntryLine> */
    private function totals(int $storeId, ?string $from, string $to, ?int $branchId): Collection
    {
        return JournalEntryLine::query()
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
    }

    private function debitSide(string $net): string
    {
        return Decimal::compare($net, '0', 2) > 0 ? $net : '0.00';
    }

    private function creditSide(string $net): string
    {
        return Decimal::compare($net, '0', 2) < 0 ? ltrim($net, '-') : '0.00';
    }
}
