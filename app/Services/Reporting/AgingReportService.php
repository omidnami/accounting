<?php

namespace App\Services\Reporting;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Invoice;
use App\Support\Decimal;
use App\Support\ReportDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AgingReportService
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, string>, total: string}
     */
    public function receivables(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): array {
        $invoices = $this->invoiceQuery(
            storeId: $storeId,
            type: InvoiceType::Sales,
            asOf: ReportDateRange::through($asOf),
            branchId: $branchId,
        )->with([
            'customer.party',
            'receiptAllocations.receipt',
            'childInvoices',
        ])->get();

        return $this->build(
            invoices: $invoices,
            asOf: ReportDateRange::through($asOf),
            outstanding: function (Invoice $invoice) use ($asOf): string {
                $received = '0.00';

                foreach ($invoice->receiptAllocations as $allocation) {
                    if ($allocation->receipt?->status === TreasuryDocumentStatus::Confirmed
                        && $allocation->receipt->receipt_date->toDateString() <= $asOf) {
                        $received = Decimal::add($received, $allocation->amount, 2);
                    }
                }

                $returned = '0.00';

                foreach ($invoice->childInvoices as $return) {
                    if ($return->type === InvoiceType::SalesReturn
                        && $return->status === InvoiceStatus::Confirmed
                        && $return->invoice_date->toDateString() <= $asOf) {
                        $returned = Decimal::add($returned, $return->total, 2);
                    }
                }

                $remaining = Decimal::subtract($invoice->total, $received, 2);

                return Decimal::subtract($remaining, $returned, 2);
            },
            party: fn (Invoice $invoice): ?array => $invoice->customer?->party ? [
                'id' => $invoice->customer->party->id,
                'code' => $invoice->customer->party->code,
                'name' => $invoice->customer->party->name,
            ] : null,
        );
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, string>, total: string}
     */
    public function payables(
        int $storeId,
        string $asOf,
        ?int $branchId = null,
    ): array {
        $invoices = $this->invoiceQuery(
            storeId: $storeId,
            type: InvoiceType::Purchase,
            asOf: ReportDateRange::through($asOf),
            branchId: $branchId,
        )->with([
            'supplier.party',
            'paymentAllocations.payment',
            'childInvoices',
        ])->get();

        return $this->build(
            invoices: $invoices,
            asOf: ReportDateRange::through($asOf),
            outstanding: function (Invoice $invoice) use ($asOf): string {
                $paid = '0.00';

                foreach ($invoice->paymentAllocations as $allocation) {
                    if ($allocation->payment?->status === TreasuryDocumentStatus::Confirmed
                        && $allocation->payment->payment_date->toDateString() <= $asOf) {
                        $paid = Decimal::add($paid, $allocation->amount, 2);
                    }
                }

                $returned = '0.00';

                foreach ($invoice->childInvoices as $return) {
                    if ($return->type === InvoiceType::PurchaseReturn
                        && $return->status === InvoiceStatus::Confirmed
                        && $return->invoice_date->toDateString() <= $asOf) {
                        $returned = Decimal::add($returned, $return->total, 2);
                    }
                }

                $remaining = Decimal::subtract($invoice->total, $paid, 2);

                return Decimal::subtract($remaining, $returned, 2);
            },
            party: fn (Invoice $invoice): ?array => $invoice->supplier?->party ? [
                'id' => $invoice->supplier->party->id,
                'code' => $invoice->supplier->party->code,
                'name' => $invoice->supplier->party->name,
            ] : null,
        );
    }

    /**
     * @return Builder<Invoice>
     */
    private function invoiceQuery(
        int $storeId,
        InvoiceType $type,
        string $asOf,
        ?int $branchId,
    ): Builder {
        return Invoice::query()
            ->where('store_id', $storeId)
            ->where('type', $type)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->whereDate('invoice_date', '<=', $asOf)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('invoice_date')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  callable(Invoice): string  $outstanding
     * @param  callable(Invoice): (?array{id: int, code: string, name: string})  $party
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, string>, total: string}
     */
    private function build(
        Collection $invoices,
        string $asOf,
        callable $outstanding,
        callable $party,
    ): array {
        $asOfDate = CarbonImmutable::parse($asOf)->startOfDay();
        $buckets = [
            'current' => '0.00',
            'days_1_30' => '0.00',
            'days_31_60' => '0.00',
            'days_61_90' => '0.00',
            'over_90' => '0.00',
        ];
        $total = '0.00';
        $rows = [];

        foreach ($invoices as $invoice) {
            $remaining = $outstanding($invoice);

            if (! Decimal::isPositive($remaining)) {
                continue;
            }

            $partyData = $party($invoice);

            if (! $partyData) {
                continue;
            }

            $dueDate = $invoice->due_date ?? $invoice->invoice_date;
            $daysOverdue = max(0, (int) $dueDate->startOfDay()->diffInDays($asOfDate, false));
            $bucket = match (true) {
                $daysOverdue === 0 => 'current',
                $daysOverdue <= 30 => 'days_1_30',
                $daysOverdue <= 60 => 'days_31_60',
                $daysOverdue <= 90 => 'days_61_90',
                default => 'over_90',
            };
            $buckets[$bucket] = Decimal::add($buckets[$bucket], $remaining, 2);
            $total = Decimal::add($total, $remaining, 2);
            $rows[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'party' => $partyData,
                'outstanding' => $remaining,
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
            ];
        }

        usort($rows, fn (array $left, array $right): int => $right['days_overdue'] <=> $left['days_overdue']);

        return [
            'rows' => $rows,
            'buckets' => $buckets,
            'total' => $total,
        ];
    }
}
