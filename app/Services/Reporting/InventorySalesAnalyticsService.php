<?php

namespace App\Services\Reporting;

use App\Services\Accounting\JalaliDateService;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Illuminate\Support\Facades\DB;

class InventorySalesAnalyticsService
{
    public function __construct(private readonly JalaliDateService $dates) {}

    /**
     * @return array<string, mixed>
     */
    public function report(
        int $storeId,
        int $branchId,
        string $from,
        string $to,
    ): array {
        $range = ReportDateRange::between($from, $to);
        $current = $this->dates->toJalali(now());
        $month = collect($this->dates->periods($current->year))
            ->firstWhere('period_number', $current->month);

        return [
            'filters' => [
                'from' => $range->from,
                'to' => $range->to,
                'month_start' => $month['starts_on']->toDateString(),
                'month_end' => $month['ends_on']->toDateString(),
            ],
            'categories' => $this->categories($storeId, $branchId, $range->from, $range->to),
            'variants' => $this->variants($storeId, $branchId, $range->from, $range->to),
            'batches' => $this->batches($storeId, $branchId, $range->from, $range->to),
            'top_month' => $this->topMonth(
                $storeId,
                $branchId,
                $month['starts_on']->toDateString(),
                min($month['ends_on']->toDateString(), now()->toDateString()),
            ),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function categories(
        int $storeId,
        int $branchId,
        string $from,
        string $to,
    ): array {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('invoices.store_id', $storeId)
            ->where('invoices.branch_id', $branchId)
            ->whereDate('invoices.invoice_date', '>=', $from)
            ->whereDate('invoices.invoice_date', '<=', $to)
            ->where(function ($query): void {
                $query->where(fn ($query) => $query
                    ->where('invoices.type', 'sales')
                    ->whereIn('invoices.status', ['confirmed', 'partially_paid', 'paid', 'reversed']))
                    ->orWhere(fn ($query) => $query
                        ->where('invoices.type', 'sales_return')
                        ->where('invoices.status', 'confirmed'));
            })
            ->selectRaw('COALESCE(product_categories.id, 0) as category_id')
            ->selectRaw("COALESCE(product_categories.name, 'بدون دسته') as category_name")
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -invoice_items.quantity ELSE invoice_items.quantity END) as quantity")
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -(invoice_items.quantity * invoice_items.unit_price - invoice_items.discount_amount) ELSE (invoice_items.quantity * invoice_items.unit_price - invoice_items.discount_amount) END) as revenue")
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -COALESCE(invoice_items.total_cost_snapshot, 0) ELSE COALESCE(invoice_items.total_cost_snapshot, 0) END) as cogs")
            ->groupBy('product_categories.id', 'product_categories.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row): array => $this->analyticsRow(
                (string) $row->category_name,
                (string) $row->quantity,
                (string) $row->revenue,
                (string) $row->cogs,
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function variants(
        int $storeId,
        int $branchId,
        string $from,
        string $to,
    ): array {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('invoices.store_id', $storeId)
            ->where('invoices.branch_id', $branchId)
            ->whereDate('invoices.invoice_date', '>=', $from)
            ->whereDate('invoices.invoice_date', '<=', $to)
            ->where(fn ($query) => $query
                ->whereNotNull('products.parent_product_id')
                ->orWhereNotNull('products.variant_color')
                ->orWhereNotNull('products.variant_size'))
            ->where(function ($query): void {
                $query->where(fn ($query) => $query
                    ->where('invoices.type', 'sales')
                    ->whereIn('invoices.status', ['confirmed', 'partially_paid', 'paid', 'reversed']))
                    ->orWhere(fn ($query) => $query
                        ->where('invoices.type', 'sales_return')
                        ->where('invoices.status', 'confirmed'));
            })
            ->select([
                'products.id as product_id',
                'products.code',
                'products.name',
                'products.variant_color',
                'products.variant_size',
                'product_categories.name as category_name',
            ])
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -invoice_items.quantity ELSE invoice_items.quantity END) as quantity")
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -(invoice_items.quantity * invoice_items.unit_price - invoice_items.discount_amount) ELSE (invoice_items.quantity * invoice_items.unit_price - invoice_items.discount_amount) END) as revenue")
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -COALESCE(invoice_items.total_cost_snapshot, 0) ELSE COALESCE(invoice_items.total_cost_snapshot, 0) END) as cogs")
            ->groupBy(
                'products.id',
                'products.code',
                'products.name',
                'products.variant_color',
                'products.variant_size',
                'product_categories.name',
            )
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row): array => [
                'label' => trim($row->name.' '.implode(' / ', array_filter([
                    $row->variant_color,
                    $row->variant_size,
                ]))),
                'code' => $row->code,
                'category' => $row->category_name,
                ...$this->analyticsRow(
                    '',
                    (string) $row->quantity,
                    (string) $row->revenue,
                    (string) $row->cogs,
                ),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function batches(
        int $storeId,
        int $branchId,
        string $from,
        string $to,
    ): array {
        return DB::table('inventory_movement_batches')
            ->join('inventory_movements', 'inventory_movements.id', '=', 'inventory_movement_batches.inventory_movement_id')
            ->join('product_batches', 'product_batches.id', '=', 'inventory_movement_batches.product_batch_id')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('inventory_movements.store_id', $storeId)
            ->where('inventory_movements.branch_id', $branchId)
            ->where('inventory_movements.movement_type', 'sale')
            ->where('inventory_movements.direction', 'out')
            ->whereDate('inventory_movements.movement_date', '>=', $from)
            ->whereDate('inventory_movements.movement_date', '<=', $to)
            ->select([
                'product_batches.id as batch_id',
                'product_batches.batch_number',
                'product_batches.expiry_date',
                'products.code',
                'products.name',
            ])
            ->selectRaw('SUM(inventory_movement_batches.quantity) as quantity')
            ->selectRaw('SUM(inventory_movement_batches.total_cost) as cogs')
            ->groupBy(
                'product_batches.id',
                'product_batches.batch_number',
                'product_batches.expiry_date',
                'products.code',
                'products.name',
            )
            ->orderBy('product_batches.expiry_date')
            ->get()
            ->map(fn ($row): array => [
                'batch_number' => $row->batch_number,
                'expiry_date' => $row->expiry_date,
                'code' => $row->code,
                'name' => $row->name,
                'quantity' => Decimal::normalize($row->quantity, 3),
                'cogs' => Decimal::normalize($row->cogs, 2),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topMonth(
        int $storeId,
        int $branchId,
        string $from,
        string $to,
    ): array {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('invoices.store_id', $storeId)
            ->where('invoices.branch_id', $branchId)
            ->whereDate('invoices.invoice_date', '>=', $from)
            ->whereDate('invoices.invoice_date', '<=', $to)
            ->where(function ($query): void {
                $query->where(fn ($query) => $query
                    ->where('invoices.type', 'sales')
                    ->whereIn('invoices.status', ['confirmed', 'partially_paid', 'paid', 'reversed']))
                    ->orWhere(fn ($query) => $query
                        ->where('invoices.type', 'sales_return')
                        ->where('invoices.status', 'confirmed'));
            })
            ->select(['products.id as product_id', 'products.code', 'products.name'])
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -invoice_items.quantity ELSE invoice_items.quantity END) as quantity")
            ->selectRaw("SUM(CASE WHEN invoices.type = 'sales_return' THEN -(invoice_items.quantity * invoice_items.unit_price - invoice_items.discount_amount) ELSE (invoice_items.quantity * invoice_items.unit_price - invoice_items.discount_amount) END) as revenue")
            ->groupBy('products.id', 'products.code', 'products.name')
            ->orderByDesc('quantity')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'product_id' => $row->product_id,
                'code' => $row->code,
                'name' => $row->name,
                'quantity' => Decimal::normalize($row->quantity, 3),
                'revenue' => Decimal::normalize($row->revenue, 2),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function analyticsRow(
        string $label,
        string $quantity,
        string $revenue,
        string $cogs,
    ): array {
        $row = [
            'quantity' => Decimal::normalize($quantity, 3),
            'revenue' => Decimal::normalize($revenue, 2),
            'cogs' => Decimal::normalize($cogs, 2),
            'gross_profit' => Decimal::subtract($revenue, $cogs, 2),
        ];

        return $label === '' ? $row : ['label' => $label, ...$row];
    }
}
