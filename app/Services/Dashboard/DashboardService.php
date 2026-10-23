<?php

namespace App\Services\Dashboard;

use App\Enums\CheckStatus;
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
use App\Services\Purchasing\SupplierPayableService;
use App\Services\Reporting\FinancialStatementReportService;
use App\Services\Sales\CustomerReceivableService;
use App\Support\Decimal;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(
        private readonly FinancialStatementReportService $statements,
        private readonly CustomerReceivableService $receivables,
        private readonly SupplierPayableService $payables,
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

        $salesToday = $this->netInvoiceTotal(
            $store,
            $branch,
            InvoiceType::Sales,
            today()->toDateString(),
            today()->toDateString(),
        );
        $data['trend'] = $this->trend($store, $branch, includePurchases: $mode === 'financial');
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
            'purchases_today' => $this->netInvoiceTotal(
                $store,
                $branch,
                InvoiceType::Purchase,
                today()->toDateString(),
                today()->toDateString(),
            ),
            'open_sales' => $this->openInvoiceCount(
                $store,
                $branch,
                InvoiceType::Sales,
            ),
            'due_checks' => (clone $pendingChecks)
                ->whereDate('due_date', '<=', today()->addDays(7))
                ->count(),
            'pending_checks_amount' => (string) (clone $pendingChecks)->sum('amount'),
            'net_profit' => $balanceSheet['net_profit'],
        ];
        $data['low_stock'] = $this->lowStock($store, $branch);

        return $data;
    }

    /**
     * @return array<int, array{date: string, sales: string, purchases: string}>
     */
    private function trend(
        Store $store,
        Branch $branch,
        bool $includePurchases,
    ): array {
        $trend = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $date = today()->subDays($offset);
            $value = $date->toDateString();
            $trend[] = [
                'date' => $value,
                'sales' => $this->netInvoiceTotal(
                    $store,
                    $branch,
                    InvoiceType::Sales,
                    $value,
                    $value,
                ),
                'purchases' => $includePurchases
                    ? $this->netInvoiceTotal(
                        $store,
                        $branch,
                        InvoiceType::Purchase,
                        $value,
                        $value,
                    )
                    : '0.00',
            ];
        }

        return $trend;
    }

    private function netInvoiceTotal(
        Store $store,
        Branch $branch,
        InvoiceType $type,
        string $from,
        string $to,
    ): string {
        $isSales = $type === InvoiceType::Sales;
        $total = (string) Invoice::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('type', $type)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->sum('total');
        $returned = (string) Invoice::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('type', $isSales ? InvoiceType::SalesReturn : InvoiceType::PurchaseReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->sum('total');

        return Decimal::subtract(
            Decimal::normalize($total, Decimal::MONEY_SCALE),
            $returned,
            Decimal::MONEY_SCALE,
        );
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

        return $invoices
            ->concat($receipts)
            ->concat($payments)
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
