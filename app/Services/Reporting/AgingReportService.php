<?php

namespace App\Services\Reporting;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Services\Parties\PartySnapshotService;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class AgingReportService
{
    public function __construct(
        private readonly PartySnapshotService $partySnapshots,
    ) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, string>, total: string}
     */
    public function receivables(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): array {
        return $this->report($storeId, InvoiceType::Sales, $asOf, $branchId);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, string>, total: string}
     */
    public function payables(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): array {
        return $this->report($storeId, InvoiceType::Purchase, $asOf, $branchId);
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function receivableRows(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): LazyCollection {
        return $this->rows($storeId, InvoiceType::Sales, $asOf, $branchId);
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function payableRows(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): LazyCollection {
        return $this->rows($storeId, InvoiceType::Purchase, $asOf, $branchId);
    }

    /** @return array<string, mixed> */
    public function receivablesPage(
        int $storeId,
        string $asOf,
        ?int $branchId,
        int $page,
    ): array {
        return $this->pageReport($storeId, InvoiceType::Sales, $asOf, $branchId, $page, 'aging-receivables');
    }

    /** @return array<string, mixed> */
    public function payablesPage(
        int $storeId,
        string $asOf,
        ?int $branchId,
        int $page,
    ): array {
        return $this->pageReport($storeId, InvoiceType::Purchase, $asOf, $branchId, $page, 'aging-payables');
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, string>, total: string}
     */
    private function report(
        int $storeId,
        InvoiceType $type,
        string $asOf,
        ?int $branchId,
    ): array {
        $asOf = ReportDateRange::through($asOf);

        return [
            ...$this->summary($storeId, $type, $asOf, $branchId),
            'rows' => $this->rows($storeId, $type, $asOf, $branchId)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pageReport(
        int $storeId,
        InvoiceType $type,
        string $asOf,
        ?int $branchId,
        int $page,
        string $cachePrefix,
    ): array {
        $asOf = ReportDateRange::through($asOf);
        $summary = app()->environment('testing')
            ? $this->summary($storeId, $type, $asOf, $branchId)
            : Cache::remember(
                "{$cachePrefix}:v2:{$storeId}:{$branchId}:{$asOf}",
                now()->addMinute(),
                fn (): array => $this->summary($storeId, $type, $asOf, $branchId),
            );
        $lines = $this->agingQuery($storeId, $type, $asOf, $branchId)
            ->paginate(50, ['*'], 'aging_page', max(1, $page))
            ->withQueryString();

        return [
            ...$summary,
            'rows' => [
                'data' => $lines->getCollection()
                    ->map(fn (object $row): array => $this->row((array) $row))
                    ->values()
                    ->all(),
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
                'total' => $lines->total(),
                'prev_page_url' => $lines->previousPageUrl(),
                'next_page_url' => $lines->nextPageUrl(),
            ],
        ];
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    private function rows(
        int $storeId,
        InvoiceType $type,
        string $asOf,
        ?int $branchId,
    ): LazyCollection {
        return $this->agingQuery($storeId, $type, $asOf, $branchId)
            ->lazy(500)
            ->map(fn (object $row): array => $this->row((array) $row));
    }

    /** @return array{buckets: array<string, string>, total: string} */
    public function summary(
        int $storeId,
        InvoiceType $type,
        string $asOf,
        ?int $branchId,
    ): array {
        $totals = DB::query()
            ->fromSub($this->agingQuery($storeId, $type, $asOf, $branchId)->reorder(), 'aging_summary')
            ->selectRaw('COALESCE(SUM(outstanding), 0) AS total')
            ->selectRaw("COALESCE(SUM(CASE WHEN bucket = 'current' THEN outstanding ELSE 0 END), 0) AS current")
            ->selectRaw("COALESCE(SUM(CASE WHEN bucket = 'days_1_30' THEN outstanding ELSE 0 END), 0) AS days_1_30")
            ->selectRaw("COALESCE(SUM(CASE WHEN bucket = 'days_31_60' THEN outstanding ELSE 0 END), 0) AS days_31_60")
            ->selectRaw("COALESCE(SUM(CASE WHEN bucket = 'days_61_90' THEN outstanding ELSE 0 END), 0) AS days_61_90")
            ->selectRaw("COALESCE(SUM(CASE WHEN bucket = 'over_90' THEN outstanding ELSE 0 END), 0) AS over_90")
            ->first();

        return [
            'total' => Decimal::normalize((string) ($totals->total ?? 0), 2),
            'buckets' => [
                'current' => Decimal::normalize((string) ($totals->current ?? 0), 2),
                'days_1_30' => Decimal::normalize((string) ($totals->days_1_30 ?? 0), 2),
                'days_31_60' => Decimal::normalize((string) ($totals->days_31_60 ?? 0), 2),
                'days_61_90' => Decimal::normalize((string) ($totals->days_61_90 ?? 0), 2),
                'over_90' => Decimal::normalize((string) ($totals->over_90 ?? 0), 2),
            ],
        ];
    }

    private function agingQuery(
        int $storeId,
        InvoiceType $type,
        string $asOf,
        ?int $branchId,
    ): Builder {
        $isReceivable = $type === InvoiceType::Sales;
        $allocations = $isReceivable ? 'receipt_allocations' : 'payment_allocations';
        $documents = $isReceivable ? 'receipts' : 'payments';
        $documentDate = $isReceivable ? 'receipt_date' : 'payment_date';
        $documentId = $isReceivable ? 'receipt_id' : 'payment_id';
        $returnType = $isReceivable ? InvoiceType::SalesReturn : InvoiceType::PurchaseReturn;

        $settled = DB::table($allocations)
            ->join($documents, "{$documents}.id", '=', "{$allocations}.{$documentId}")
            ->leftJoin('checks', 'checks.id', '=', "{$documents}.check_id")
            ->leftJoin('journal_entries as bounce_entries', 'bounce_entries.id', '=', 'checks.bounce_entry_id')
            ->whereColumn("{$allocations}.invoice_id", 'invoices.id')
            ->whereDate("{$documents}.{$documentDate}", '<=', $asOf)
            ->where(function (Builder $query) use ($documents, $asOf): void {
                $query->where("{$documents}.status", 'confirmed')
                    ->orWhere(function (Builder $query) use ($documents, $asOf): void {
                        $query->where("{$documents}.status", 'bounced')
                            ->whereDate('bounce_entries.entry_date', '>', $asOf);
                    });
            })
            ->selectRaw("COALESCE(SUM({$allocations}.amount), 0)");
        $returned = DB::table('invoices as return_invoices')
            ->whereColumn('return_invoices.parent_invoice_id', 'invoices.id')
            ->where('return_invoices.type', $returnType->value)
            ->where('return_invoices.status', InvoiceStatus::Confirmed->value)
            ->whereDate('return_invoices.invoice_date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(return_invoices.total), 0)');
        $base = DB::table('invoices')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'invoices.supplier_id')
            ->leftJoin(
                'parties',
                'parties.id',
                '=',
                $isReceivable ? 'customers.party_id' : 'suppliers.party_id',
            )
            ->where('invoices.store_id', $storeId)
            ->where('invoices.type', $type->value)
            ->whereNotNull('parties.id')
            ->whereIn('invoices.status', [
                InvoiceStatus::Confirmed->value,
                InvoiceStatus::PartiallyPaid->value,
                InvoiceStatus::Paid->value,
                InvoiceStatus::Reversed->value,
            ])
            ->whereDate('invoices.invoice_date', '<=', $asOf)
            ->when($branchId !== null, fn (Builder $query) => $query->where('invoices.branch_id', $branchId))
            ->select([
                'invoices.id as invoice_id',
                'invoices.invoice_number',
                'invoices.invoice_date',
                'invoices.due_date',
                'invoices.total',
                'parties.id as party_id',
                'parties.code as party_code',
                'parties.name as party_name',
                'invoices.party_snapshot',
            ])
            ->selectSub($settled, 'received')
            ->selectSub($returned, 'returned');
        $outstandingValues = DB::query()
            ->fromSub($base, 'aging_base')
            ->select('*')
            ->selectRaw('(total - COALESCE(received, 0) - COALESCE(returned, 0)) AS outstanding');
        $outstanding = DB::query()
            ->fromSub($outstandingValues, 'aging_calculated')
            ->select('*')
            ->where('outstanding', '>', 0);
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $daysExpression = $isSqlite
            ? 'julianday(?) - julianday(COALESCE(due_date, invoice_date))'
            : 'DATEDIFF(?, COALESCE(due_date, invoice_date))';
        $days = DB::query()
            ->fromSub($outstanding, 'aging_outstanding')
            ->select('*')
            ->selectRaw(
                $isSqlite
                    ? "MAX(0, CAST({$daysExpression} AS INTEGER)) AS days_overdue"
                    : "GREATEST(0, {$daysExpression}) AS days_overdue",
                [$asOf],
            );

        return DB::query()
            ->fromSub($days, 'aging_days')
            ->select('*')
            ->selectRaw("CASE
                WHEN days_overdue = 0 THEN 'current'
                WHEN days_overdue <= 30 THEN 'days_1_30'
                WHEN days_overdue <= 60 THEN 'days_31_60'
                WHEN days_overdue <= 90 THEN 'days_61_90'
                ELSE 'over_90'
            END AS bucket")
            ->orderByDesc('days_overdue')
            ->orderBy('due_date')
            ->orderBy('invoice_id');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $party = $this->partySnapshots->fromDatabase($row['party_snapshot'] ?? null, [
            'id' => (int) $row['party_id'],
            'code' => $row['party_code'],
            'name' => $row['party_name'],
        ]);

        return [
            'invoice_id' => (int) $row['invoice_id'],
            'invoice_number' => $row['invoice_number'],
            'invoice_date' => substr((string) $row['invoice_date'], 0, 10),
            'due_date' => substr((string) ($row['due_date'] ?? $row['invoice_date']), 0, 10),
            'party' => [
                'id' => (int) ($party['party_id'] ?? $party['id']),
                'code' => $party['code'],
                'name' => $party['name'],
                'legacy_current' => $party['legacy_current'] ?? false,
            ],
            'outstanding' => Decimal::normalize($row['outstanding'], 2),
            'days_overdue' => (int) $row['days_overdue'],
            'bucket' => $row['bucket'],
        ];
    }
}
