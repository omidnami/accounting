<?php

namespace App\Services\Reporting;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\InvoiceItem;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Illuminate\Support\Collection;

class SalesOperationsReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(
        int $storeId,
        string $from,
        string $to,
        ?int $branchId = null,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $items = InvoiceItem::query()
            ->whereHas('invoice', function ($query) use ($storeId, $range, $branchId): void {
                $query->where('store_id', $storeId)
                    ->whereDate('invoice_date', '>=', $range->from)
                    ->whereDate('invoice_date', '<=', $range->to)
                    ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                    ->where(function ($query): void {
                        $query->where(function ($query): void {
                            $query->where('type', InvoiceType::Sales)
                                ->whereIn('status', [
                                    InvoiceStatus::Confirmed,
                                    InvoiceStatus::PartiallyPaid,
                                    InvoiceStatus::Paid,
                                    InvoiceStatus::Reversed,
                                ]);
                        })->orWhere(function ($query): void {
                            $query->where('type', InvoiceType::SalesReturn)
                                ->where('status', InvoiceStatus::Confirmed);
                        });
                    });
            })
            ->with('invoice:id,store_id,branch_id,type,invoice_date,invoice_number,status')
            ->orderBy('invoice_id')
            ->orderBy('sort_order')
            ->get();
        $daily = $this->daily($items);
        $products = $this->products($items);
        $revenue = $daily->reduce(
            fn (string $total, array $row): string => Decimal::add($total, $row['revenue'], 2),
            '0.00',
        );
        $cogs = $daily->reduce(
            fn (string $total, array $row): string => Decimal::add($total, $row['cogs'], 2),
            '0.00',
        );
        $grossProfit = Decimal::subtract($revenue, $cogs, 2);
        $incompleteCogsCount = $items
            ->filter(fn (InvoiceItem $item): bool => $item->total_cost_snapshot === null)
            ->count();

        return [
            'summary' => [
                'revenue' => $revenue,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'gross_margin' => Decimal::isZero($revenue)
                    ? '0.00'
                    : Decimal::divide(Decimal::multiply($grossProfit, '100', 4), $revenue, 2),
                'cogs_complete' => $incompleteCogsCount === 0,
                'incomplete_cogs_count' => $incompleteCogsCount,
                'sales_invoices' => $items->where('invoice.type', InvoiceType::Sales)
                    ->pluck('invoice.invoice_number')
                    ->unique()
                    ->count(),
                'return_invoices' => $items->where('invoice.type', InvoiceType::SalesReturn)
                    ->pluck('invoice.invoice_number')
                    ->unique()
                    ->count(),
            ],
            'daily' => $daily,
            'products' => $products,
        ];
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return Collection<int, mixed>
     */
    private function daily(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (InvoiceItem $item): string => $item->invoice->invoice_date->toDateString())
            ->map(function (Collection $rows, string $date): array {
                $revenue = '0.00';
                $cogs = '0.00';
                $quantity = '0.000';
                $incompleteCogs = 0;

                foreach ($rows as $item) {
                    $sign = $item->invoice->type === InvoiceType::SalesReturn ? '-1' : '1';
                    $lineRevenue = Decimal::subtract(
                        Decimal::multiply($item->quantity, $item->unit_price, 2),
                        $item->discount_amount,
                        2,
                    );
                    $revenue = Decimal::add(
                        $revenue,
                        Decimal::multiply($lineRevenue, $sign, 2),
                        2,
                    );
                    $cogs = Decimal::add(
                        $cogs,
                        Decimal::multiply($item->total_cost_snapshot ?? 0, $sign, 2),
                        2,
                    );
                    $quantity = Decimal::add(
                        $quantity,
                        Decimal::multiply($item->quantity, $sign, 3),
                        3,
                    );
                    $incompleteCogs += $item->total_cost_snapshot === null ? 1 : 0;
                }

                return [
                    'date' => $date,
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'gross_profit' => Decimal::subtract($revenue, $cogs, 2),
                    'quantity' => $quantity,
                    'cogs_complete' => $incompleteCogs === 0,
                ];
            })
            ->sortBy('date')
            ->values();
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return Collection<int, mixed>
     */
    private function products(Collection $items): Collection
    {
        return $items
            ->groupBy('product_id')
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $revenue = '0.00';
                $cogs = '0.00';
                $quantity = '0.000';
                $incompleteCogs = 0;

                foreach ($rows as $item) {
                    $sign = $item->invoice->type === InvoiceType::SalesReturn ? '-1' : '1';
                    $lineRevenue = Decimal::subtract(
                        Decimal::multiply($item->quantity, $item->unit_price, 2),
                        $item->discount_amount,
                        2,
                    );
                    $revenue = Decimal::add(
                        $revenue,
                        Decimal::multiply($lineRevenue, $sign, 2),
                        2,
                    );
                    $cogs = Decimal::add(
                        $cogs,
                        Decimal::multiply($item->total_cost_snapshot ?? 0, $sign, 2),
                        2,
                    );
                    $quantity = Decimal::add(
                        $quantity,
                        Decimal::multiply($item->quantity, $sign, 3),
                        3,
                    );
                    $incompleteCogs += $item->total_cost_snapshot === null ? 1 : 0;
                }

                $grossProfit = Decimal::subtract($revenue, $cogs, 2);

                return [
                    'product_id' => $first->product_id,
                    'code' => $first->product_code,
                    'name' => $first->product_name,
                    'unit' => $first->unit,
                    'quantity' => $quantity,
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'gross_profit' => $grossProfit,
                    'gross_margin' => Decimal::isZero($revenue)
                        ? '0.00'
                        : Decimal::divide(Decimal::multiply($grossProfit, '100', 4), $revenue, 2),
                    'cogs_complete' => $incompleteCogs === 0,
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }
}
