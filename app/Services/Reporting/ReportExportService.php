<?php

namespace App\Services\Reporting;

use App\Enums\AccountLevel;
use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Models\Account;
use App\Models\Party;
use App\Models\Product;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCardexReportService;
use App\Support\Decimal;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public function __construct(
        private readonly CsvExportService $csv,
        private readonly TrialBalanceReportService $trialBalance,
        private readonly FinancialStatementReportService $statements,
        private readonly JournalReportService $journals,
        private readonly AccountLedgerReportService $ledgers,
        private readonly PartyStatementReportService $partyStatements,
        private readonly AgingReportService $aging,
        private readonly SalesOperationsReportService $sales,
        private readonly InventoryCardexReportService $cardex,
    ) {}

    public function financial(
        Store $store,
        string $report,
        string $from,
        string $to,
        ?int $branchId,
        ?int $accountId,
    ): StreamedResponse {
        if ($report === 'trial') {
            $data = $this->trialBalance->report($store->id, $from, $to, $branchId);
            $rows = $data['rows']->map(fn (array $row): array => [
                $row['code'],
                $row['name'],
                $row['type'],
                $row['level'],
                $row['opening_debit'],
                $row['opening_credit'],
                $row['debit'],
                $row['credit'],
                $row['closing_debit'],
                $row['closing_credit'],
            ]);
            $rows->push([
                '',
                'جمع کل',
                '',
                '',
                $data['total_opening_debit'],
                $data['total_opening_credit'],
                $data['total_debit'],
                $data['total_credit'],
                $data['total_closing_debit'],
                $data['total_closing_credit'],
            ]);
            $rows->push([
                '',
                $data['balanced'] ? 'کنترل: متوازن' : 'کنترل: نامتوازن',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ]);

            return $this->csv->download('trial-balance-'.$from.'-'.$to.'.csv', [
                'کد حساب', 'حساب', 'نوع', 'سطح',
                'مانده اول بدهکار', 'مانده اول بستانکار',
                'گردش بدهکار', 'گردش بستانکار',
                'مانده پایان بدهکار', 'مانده پایان بستانکار',
            ], $rows);
        }

        if ($report === 'income') {
            $data = $this->statements->incomeStatement($store->id, $from, $to, $branchId);
            $rows = collect($data['revenues'])
                ->map(fn (array $row): array => ['درآمد', $row['code'], $row['name'], $row['amount']])
                ->concat(collect($data['expenses'])
                    ->map(fn (array $row): array => ['هزینه', $row['code'], $row['name'], $row['amount']]))
                ->concat([
                    ['جمع', '', 'کل درآمد', $data['total_revenue']],
                    ['جمع', '', 'کل هزینه', $data['total_expense']],
                    ['نتیجه', '', 'سود خالص دوره', $data['net_profit']],
                ]);

            return $this->csv->download('income-statement-'.$from.'-'.$to.'.csv', [
                'نوع', 'کد حساب', 'حساب', 'مبلغ',
            ], $rows);
        }

        if ($report === 'balance') {
            $data = $this->statements->balanceSheet($store->id, $to, $branchId);
            $rows = collect($data['assets'])
                ->map(fn (array $row): array => ['دارایی', $row['code'], $row['name'], $row['amount']])
                ->concat(collect($data['liabilities'])
                    ->map(fn (array $row): array => ['بدهی', $row['code'], $row['name'], $row['amount']]))
                ->concat(collect($data['equity'])
                    ->map(fn (array $row): array => ['سرمایه', $row['code'], $row['name'], $row['amount']]))
                ->concat([
                    ['سرمایه', '', 'سود دوره جاری', $data['net_profit']],
                    ['جمع', '', 'جمع دارایی‌ها', $data['total_assets']],
                    ['جمع', '', 'جمع بدهی‌ها', $data['total_liabilities']],
                    ['جمع', '', 'جمع حقوق صاحبان سهام', $data['total_equity']],
                    ['کنترل', '', 'وضعیت تراز', $data['balanced'] ? 'متوازن' : 'نامتوازن'],
                ]);

            return $this->csv->download('balance-sheet-'.$to.'.csv', [
                'نوع', 'کد حساب', 'حساب', 'مبلغ',
            ], $rows);
        }

        if ($report === 'journal') {
            $rows = LazyCollection::make(function () use ($store, $from, $to, $branchId) {
                $debit = '0.00';
                $credit = '0.00';

                foreach ($this->journals->lineRows($store->id, $from, $to, $branchId) as $row) {
                    $debit = Decimal::add($debit, $row['debit'], 2);
                    $credit = Decimal::add($credit, $row['credit'], 2);

                    yield array_values($row);
                }

                yield ['', 'جمع کل', '', '', '', $debit, $credit, ''];
            });

            return $this->csv->download('journal-'.$from.'-'.$to.'.csv', [
                'تاریخ', 'شماره سند', 'شرح سند', 'کد حساب', 'حساب', 'بدهکار', 'بستانکار', 'شرح ردیف',
            ], $rows);
        }

        $account = Account::query()
            ->where('store_id', $store->id)
            ->where('level', AccountLevel::Subsidiary)
            ->when(
                $accountId !== null,
                fn ($query) => $query->whereKey($accountId),
                fn ($query) => $query->where('code', '1101'),
            )
            ->firstOrFail();
        $data = $this->ledgers->report($account, $store->id, $from, $to, $branchId);
        $rows = $data['rows']->map(fn (array $row): array => [
            $row['date'],
            $row['entry_number'],
            $row['description'],
            $row['account_code'],
            $row['account_name'],
            $row['debit'],
            $row['credit'],
            $row['balance'],
        ]);
        $rows->push([
            '',
            '',
            'مانده اول دوره',
            $account->code,
            $account->name,
            '',
            '',
            $data['opening_balance'],
        ]);
        $rows->push([
            '',
            '',
            'جمع گردش و مانده پایان',
            $account->code,
            $account->name,
            $data['debit_total'],
            $data['credit_total'],
            $data['closing_balance'],
        ]);

        return $this->csv->download('account-ledger-'.$account->code.'-'.$from.'-'.$to.'.csv', [
            'تاریخ', 'شماره سند', 'شرح', 'کد حساب', 'حساب', 'بدهکار', 'بستانکار', 'مانده',
        ], $rows);
    }

    public function operations(
        Store $store,
        string $report,
        string $from,
        string $to,
        ?int $branchId,
        ?int $partyId,
    ): StreamedResponse {
        if ($report === 'statement') {
            $party = Party::query()
                ->where('store_id', $store->id)
                ->when($partyId !== null, fn ($query) => $query->whereKey($partyId))
                ->orderBy('name')
                ->firstOrFail();
            $data = $this->partyStatements->report($party, $store->id, $from, $to, $branchId);
            $rows = $data['rows']->map(fn (array $row): array => [
                $row['date'],
                $row['entry_number'],
                $row['description'],
                $row['account_code'],
                $row['account_name'],
                $row['debit'],
                $row['credit'],
                $row['balance'],
            ]);
            $rows->push([
                '',
                '',
                'مانده اول دوره',
                '',
                '',
                '',
                '',
                $data['opening_balance'],
            ]);
            $rows->push([
                '',
                '',
                'جمع گردش و مانده پایان',
                '',
                '',
                $data['debit_total'],
                $data['credit_total'],
                $data['closing_balance'],
            ]);

            return $this->csv->download('statement-'.$party->code.'-'.$from.'-'.$to.'.csv', [
                'تاریخ', 'شماره سند', 'شرح', 'کد حساب', 'حساب', 'بدهکار', 'بستانکار', 'مانده',
            ], $rows);
        }

        if (str_starts_with($report, 'aging_')) {
            $isReceivable = $report === 'aging_receivable';
            $data = $isReceivable
                ? $this->aging->receivables($store->id, $to, $branchId)
                : $this->aging->payables($store->id, $to, $branchId);
            $rows = collect($data['rows'])->map(fn (array $row): array => [
                $row['party']['name'],
                $row['party']['code'],
                $row['invoice_number'],
                $row['invoice_date'],
                $row['due_date'],
                $row['outstanding'],
                $row['days_overdue'],
                $row['bucket'],
            ]);
            $rows->push(['جمع کل', '', '', '', '', $data['total'], '', '']);

            return $this->csv->download(($isReceivable ? 'receivables' : 'payables').'-aging-'.$to.'.csv', [
                'طرف‌حساب', 'کد طرف‌حساب', 'فاکتور', 'تاریخ', 'سررسید', 'مانده', 'روز تأخیر', 'بازه',
            ], $rows);
        }

        $data = $this->sales->report($store->id, $from, $to, $branchId);

        if ($report === 'sales_daily') {
            $rows = $data['daily']->map(fn (array $row): array => [
                $row['date'],
                $row['quantity'],
                $row['revenue'],
                $row['cogs'],
                $row['cogs_complete'] ? $row['gross_profit'] : '',
                $row['cogs_complete'] ? 'بله' : 'خیر',
            ]);
            $rows->push([
                'جمع کل',
                '',
                $data['summary']['revenue'],
                $data['summary']['cogs'],
                $data['summary']['cogs_complete'] ? $data['summary']['gross_profit'] : '',
                $data['summary']['cogs_complete'] ? 'بله' : 'خیر',
            ]);

            return $this->csv->download('sales-daily-'.$from.'-'.$to.'.csv', [
                'تاریخ', 'مقدار', 'فروش خالص', 'COGS', 'سود ناخالص', 'COGS کامل',
            ], $rows);
        }

        $rows = $data['products']->map(fn (array $row): array => [
            $row['code'],
            $row['name'],
            $row['unit'],
            $row['quantity'],
            $row['revenue'],
            $row['cogs'],
            $row['cogs_complete'] ? $row['gross_profit'] : '',
            $row['cogs_complete'] ? $row['gross_margin'] : '',
            $row['cogs_complete'] ? 'بله' : 'خیر',
        ]);
        $rows->push([
            'جمع کل',
            '',
            '',
            '',
            $data['summary']['revenue'],
            $data['summary']['cogs'],
            $data['summary']['cogs_complete'] ? $data['summary']['gross_profit'] : '',
            $data['summary']['cogs_complete'] ? $data['summary']['gross_margin'] : '',
            $data['summary']['cogs_complete'] ? 'بله' : 'خیر',
        ]);

        return $this->csv->download('sales-products-'.$from.'-'.$to.'.csv', [
            'کد کالا', 'کالا', 'واحد', 'مقدار', 'فروش خالص', 'COGS', 'سود ناخالص', 'حاشیه سود', 'COGS کامل',
        ], $rows);
    }

    public function cardex(
        Store $store,
        int $productId,
        int $warehouseId,
        string $from,
        string $to,
        ?InventoryMovementType $movementType,
        ?InventoryDirection $direction,
        bool $includeFinancials,
    ): StreamedResponse {
        $product = Product::query()->findOrFail($productId);
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $headers = ['تاریخ', 'نوع', 'جهت', 'مقدار', 'شرح', 'مرجع'];

        if ($includeFinancials) {
            array_push($headers, 'بهای واحد', 'بهای کل', 'ارزش پس از گردش');
        }

        $headers[] = 'مانده تعدادی';

        $rows = LazyCollection::make(function () use (
            $store,
            $product,
            $warehouse,
            $from,
            $to,
            $movementType,
            $direction,
            $includeFinancials,
        ) {
            $page = 1;

            do {
                $data = $this->cardex->report(
                    storeId: $store->id,
                    productId: $product->id,
                    warehouseId: $warehouse->id,
                    from: $from,
                    to: $to,
                    movementType: $movementType,
                    direction: $direction,
                    includeFinancials: $includeFinancials,
                    perPage: 100,
                    page: $page,
                );

                foreach ($data['movements']->items() as $movement) {
                    $row = [
                        $movement['movement_date'],
                        $movement['movement_type'],
                        $movement['direction'],
                        $movement['quantity'],
                        $movement['description'],
                        $movement['reference'],
                    ];

                    if ($includeFinancials) {
                        array_push($row, $movement['unit_cost'], $movement['total_cost'], $movement['value_after']);
                    }

                    $row[] = $movement['quantity_after'];

                    yield $row;
                }

                $page++;
            } while ($page <= $data['movements']->lastPage());
        });

        return $this->csv->download(
            'cardex-'.$product->code.'-'.$warehouse->code.'-'.$from.'-'.$to.'.csv',
            $headers,
            $rows,
        );
    }
}
