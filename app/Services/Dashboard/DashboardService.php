<?php

namespace App\Services\Dashboard;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Check;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\JalaliDateService;
use App\Services\Purchasing\SupplierPayableService;
use App\Services\Reporting\FinancialStatementReportService;
use App\Services\Sales\CustomerReceivableService;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly FinancialStatementReportService $statements,
        private readonly CustomerReceivableService $receivables,
        private readonly SupplierPayableService $payables,
        private readonly JalaliDateService $dates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(
        User $user,
        Store $store,
        Branch $branch,
        FiscalYear $fiscalYear,
    ): array {
        return DB::transaction(
            fn (): array => $this->buildOverview(
                $user,
                $store,
                $branch,
                $fiscalYear,
            ),
            attempts: 3,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(
        User $user,
        Store $store,
        Branch $branch,
        FiscalYear $fiscalYear,
    ): array {
        $mode = match ($user->role) {
            UserRole::Manager, UserRole::Accountant => 'financial',
            UserRole::Cashier => 'sales',
            UserRole::Warehouse => 'inventory',
            UserRole::Viewer => 'limited',
        };
        $data = [
            'mode' => $mode,
            'store' => ['name' => $store->name],
            'branch' => ['name' => $branch->name],
            'fiscal_year' => [
                'title' => $fiscalYear->title,
                'ends_on' => $fiscalYear->ends_on->toDateString(),
            ],
            'metrics' => [],
            'trend' => [],
            'monthly_trend' => [],
            'low_stock' => ['count' => 0, 'items' => []],
            'recent_activity' => [],
            'setup_required' => false,
        ];

        if ($mode === 'inventory') {
            $data['low_stock'] = $this->lowStock($store, $branch);

            return $data;
        }

        if ($mode === 'limited') {
            return $data;
        }

        $data['trend'] = $this->trend($store, $branch, includePurchases: $mode === 'financial');
        $data['monthly_trend'] = $this->monthlyTrend(
            $store,
            $branch,
            includePurchases: $mode === 'financial',
        );
        $salesToday = $data['trend'][6]['sales'];
        $data['recent_activity'] = $this->recentActivity($store, $branch, $mode === 'financial');

        if ($mode === 'sales') {
            $data['metrics'] = [
                'sales_today' => $salesToday,
                'open_sales' => $this->openInvoiceCount(
                    $store,
                    $branch,
                    InvoiceType::Sales,
                ),
                'draft_sales' => Invoice::query()
                    ->where('store_id', $store->id)
                    ->where('branch_id', $branch->id)
                    ->where('type', InvoiceType::Sales)
                    ->where('status', InvoiceStatus::Draft)
                    ->count(),
            ];

            return $data;
        }

        $balanceSheet = $this->statements->balanceSheet(
            $store->id,
            today()->toDateString(),
            $branch->id,
        );
        $incomeStatement = $this->statements->incomeStatement(
            $store->id,
            $fiscalYear->starts_on->toDateString(),
            today()->toDateString(),
            $branch->id,
        );
        $assetAmount = fn (string $code): string => (string) (
            $balanceSheet['assets']->firstWhere('code', $code)['amount'] ?? '0.00'
        );
        $pendingChecks = Check::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('status', CheckStatus::Pending);
        $data['metrics'] = [
            'cash_balance' => $assetAmount('1101'),
            'bank_balance' => $assetAmount('1102'),
            'receivables' => $this->receivables->storeOutstanding($store->id, $branch->id),
            'payables' => $this->payables->storeOutstanding($store->id, $branch->id),
            'sales_today' => $salesToday,
            'purchases_today' => $data['trend'][6]['purchases'],
            'open_sales' => $this->openInvoiceCount(
                $store,
                $branch,
                InvoiceType::Sales,
            ),
            'overdue_checks' => (clone $pendingChecks)
                ->whereDate('due_date', '<', today())
                ->count(),
            'due_soon_checks' => (clone $pendingChecks)
                ->whereDate('due_date', '>=', today())
                ->whereDate('due_date', '<=', today()->addDays(7))
                ->count(),
            'pending_checks_amount' => Decimal::normalize(
                (string) (clone $pendingChecks)->sum('amount'),
                Decimal::MONEY_SCALE,
            ),
            'net_profit' => $incomeStatement['net_profit'],
        ];
        $data['low_stock'] = $this->lowStock($store, $branch);

        return $data;
    }

    /**
     * @return array<int, array{label: string, date: string, sales: string, purchases: string}>
     */
    private function monthlyTrend(
        Store $store,
        Branch $branch,
        bool $includePurchases,
    ): array {
        $current = $this->dates->toJalali(today());
        $months = [];
        $currentSerial = $current->year * 12 + $current->month - 1;

        for ($offset = 5; $offset >= 0; $offset--) {
            $serial = $currentSerial - $offset;
            $year = intdiv($serial, 12);
            $month = ($serial % 12) + 1;
            $period = collect($this->dates->periods($year))
                ->firstWhere('period_number', $month);

            if ($period) {
                $months[] = $period;
            }
        }

        $from = $months[0]['starts_on']->toDateString();
        $to = today()->toDateString();
        $sales = $this->invoiceTotalsByDate(
            $store,
            $branch,
            InvoiceType::Sales,
            $from,
            $to,
        );
        $purchases = $includePurchases
            ? $this->invoiceTotalsByDate(
                $store,
                $branch,
                InvoiceType::Purchase,
                $from,
                $to,
            )
            : collect();
        $salesReturns = $this->invoiceTotalsByDate(
            $store,
            $branch,
            InvoiceType::SalesReturn,
            $from,
            $to,
        );
        $purchaseReturns = $includePurchases
            ? $this->invoiceTotalsByDate(
                $store,
                $branch,
                InvoiceType::PurchaseReturn,
                $from,
                $to,
            )
            : collect();

        return collect($months)->map(function (array $period) use (
            $sales,
            $salesReturns,
            $purchases,
            $purchaseReturns,
        ): array {
            $periodFrom = $period['starts_on']->toDateString();
            $periodTo = $period['ends_on']->toDateString();

            return [
                'label' => $period['name'],
                'date' => $periodFrom,
                'sales' => Decimal::subtract(
                    $this->sumDateTotals($sales, $periodFrom, $periodTo),
                    $this->sumDateTotals($salesReturns, $periodFrom, $periodTo),
                    Decimal::MONEY_SCALE,
                ),
                'purchases' => Decimal::subtract(
                    $this->sumDateTotals($purchases, $periodFrom, $periodTo),
                    $this->sumDateTotals($purchaseReturns, $periodFrom, $periodTo),
                    Decimal::MONEY_SCALE,
                ),
            ];
        })->all();
    }

    /**
     * @param  Collection<int|string, numeric-string>  $totals
     */
    private function sumDateTotals(
        Collection $totals,
        string $from,
        string $to,
    ): string {
        $sum = '0.00';

        foreach ($totals as $date => $total) {
            $date = (string) $date;

            if ($date >= $from && $date <= $to) {
                $sum = Decimal::add($sum, $total, Decimal::MONEY_SCALE);
            }
        }

        return $sum;
    }

    /**
     * @return array<int, array{date: string, sales: string, purchases: string}>
     */
    private function trend(
        Store $store,
        Branch $branch,
        bool $includePurchases,
    ): array {
        $from = today()->subDays(6)->toDateString();
        $to = today()->toDateString();
        $sales = $this->invoiceTotalsByDate(
            $store,
            $branch,
            InvoiceType::Sales,
            $from,
            $to,
        );
        $purchases = $includePurchases
            ? $this->invoiceTotalsByDate(
                $store,
                $branch,
                InvoiceType::Purchase,
                $from,
                $to,
            )
            : collect();
        $salesReturns = $this->invoiceTotalsByDate(
            $store,
            $branch,
            InvoiceType::SalesReturn,
            $from,
            $to,
        );
        $purchaseReturns = $includePurchases
            ? $this->invoiceTotalsByDate(
                $store,
                $branch,
                InvoiceType::PurchaseReturn,
                $from,
                $to,
            )
            : collect();
        $trend = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $date = today()->subDays($offset);
            $value = $date->toDateString();
            $trend[] = [
                'date' => $value,
                'sales' => Decimal::subtract(
                    $sales->get($value, '0.00'),
                    $salesReturns->get($value, '0.00'),
                    Decimal::MONEY_SCALE,
                ),
                'purchases' => $includePurchases
                    ? Decimal::subtract(
                        $purchases->get($value, '0.00'),
                        $purchaseReturns->get($value, '0.00'),
                        Decimal::MONEY_SCALE,
                    )
                    : '0.00',
            ];
        }

        return $trend;
    }

    /**
     * @return Collection<int|string, numeric-string>
     */
    private function invoiceTotalsByDate(
        Store $store,
        Branch $branch,
        InvoiceType $type,
        string $from,
        string $to,
    ): Collection {
        $statuses = in_array($type, [
            InvoiceType::SalesReturn,
            InvoiceType::PurchaseReturn,
        ], true)
            ? [InvoiceStatus::Confirmed]
            : [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ];

        return Invoice::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('type', $type)
            ->whereIn('status', $statuses)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->selectRaw('DATE(invoice_date) as report_date, SUM(total) as total')
            ->groupByRaw('DATE(invoice_date)')
            ->pluck('total', 'report_date')
            ->map(fn ($total): string => Decimal::normalize(
                (string) $total,
                Decimal::MONEY_SCALE,
            ));
    }

    private function openInvoiceCount(
        Store $store,
        Branch $branch,
        InvoiceType $type,
    ): int {
        return Invoice::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('type', $type)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
            ])
            ->count();
    }

    /**
     * @return array{
     *     count: int,
     *     items: array<int, array{
     *         id: int, code: string, name: string, unit: string,
     *         quantity: string, reorder_level: string
     *     }>
     * }
     */
    private function lowStock(Store $store, Branch $branch): array
    {
        $balances = InventoryBalance::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(function (Collection $rows): string {
                $quantity = '0.000';

                foreach ($rows as $row) {
                    $quantity = Decimal::add(
                        $quantity,
                        $row->quantity,
                        Decimal::QUANTITY_SCALE,
                    );
                }

                return $quantity;
            });
        $items = Product::query()
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'unit', 'reorder_level'])
            ->map(function (Product $product) use ($balances): array {
                $quantity = $balances->get($product->id, '0.000');

                return [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $quantity,
                    'reorder_level' => $product->reorder_level,
                ];
            })
            ->filter(fn (array $row): bool => Decimal::compare(
                $row['quantity'],
                $row['reorder_level'],
                Decimal::QUANTITY_SCALE,
            ) <= 0)
            ->values();

        return [
            'count' => $items->count(),
            'items' => $items->take(5)->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(
        Store $store,
        Branch $branch,
        bool $includePurchases,
    ): array {
        $invoices = Invoice::query()
            ->with(['customer.party:id,name', 'supplier.party:id,name'])
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->when(
                ! $includePurchases,
                fn ($query) => $query->where('type', InvoiceType::Sales),
            )
            ->whereIn('type', [
                InvoiceType::Sales,
                InvoiceType::Purchase,
                InvoiceType::SalesReturn,
                InvoiceType::PurchaseReturn,
            ])
            ->latest('invoice_date')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'kind' => $invoice->type->value,
                'number' => $invoice->invoice_number,
                'party' => in_array($invoice->type, [
                    InvoiceType::Sales,
                    InvoiceType::SalesReturn,
                ], true)
                    ? $invoice->customer?->party?->name
                    : $invoice->supplier?->party?->name,
                'date' => $invoice->invoice_date->toDateString(),
                'amount' => $invoice->total,
                'status' => $invoice->status->value,
            ]);

        if (! $includePurchases) {
            return $invoices->take(8)->all();
        }

        $receipts = Receipt::query()
            ->with('customer.party:id,name')
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->latest('receipt_date')
            ->latest('id')
            ->limit(4)
            ->get()
            ->map(fn (Receipt $receipt): array => [
                'id' => $receipt->id,
                'kind' => 'receipt',
                'number' => $receipt->receipt_number,
                'party' => $receipt->customer?->party?->name,
                'date' => $receipt->receipt_date->toDateString(),
                'amount' => $receipt->amount,
                'status' => $receipt->status->value,
            ]);
        $payments = Payment::query()
            ->with('supplier.party:id,name')
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->latest('payment_date')
            ->latest('id')
            ->limit(4)
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'kind' => 'payment',
                'number' => $payment->payment_number,
                'party' => $payment->supplier?->party?->name,
                'date' => $payment->payment_date->toDateString(),
                'amount' => $payment->amount,
                'status' => $payment->status->value,
            ]);
        $checks = Check::query()
            ->with(['customer.party:id,name', 'supplier.party:id,name'])
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(4)
            ->get()
            ->map(fn (Check $check): array => [
                'id' => $check->id,
                'kind' => 'check',
                'number' => $check->document_number ?? $check->check_number,
                'party' => $check->type === CheckType::Receivable
                    ? $check->customer?->party?->name
                    : $check->supplier?->party?->name,
                'date' => $check->created_at?->toDateString()
                    ?? $check->due_date->toDateString(),
                'amount' => $check->amount,
                'status' => $check->status->value,
            ]);

        return $invoices
            ->concat($receipts)
            ->concat($payments)
            ->concat($checks)
            ->sortByDesc(fn (array $row): string => $row['date'].'-'.str_pad(
                (string) $row['id'],
                10,
                '0',
                STR_PAD_LEFT,
            ))
            ->take(8)
            ->values()
            ->all();
    }
}
