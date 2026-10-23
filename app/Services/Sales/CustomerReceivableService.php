<?php

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;

class CustomerReceivableService
{
    public function customerOutstanding(Customer $customer, bool $lockForUpdate = false): string
    {
        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('type', InvoiceType::Sales)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
            ])
            ->with([
                'receiptAllocations.receipt',
                'childInvoices',
            ])
            ->orderBy('id')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get();
        $total = '0.00';

        foreach ($invoices as $invoice) {
            $outstanding = $this->outstandingFromLoadedRelations($invoice);

            if (Decimal::isPositive($outstanding)) {
                $total = Decimal::add($total, $outstanding, Decimal::MONEY_SCALE);
            }
        }

        return $total;
    }

    public function outstandingForInvoice(Invoice $invoice): string
    {
        $invoice->loadMissing([
            'receiptAllocations.receipt',
            'childInvoices',
        ]);

        return $this->outstandingFromLoadedRelations($invoice);
    }

    public function storeOutstanding(int $storeId, ?int $branchId = null): string
    {
        $invoices = Invoice::query()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('type', InvoiceType::Sales)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
            ])
            ->with([
                'receiptAllocations.receipt',
                'childInvoices',
            ])
            ->get();
        $total = '0.00';

        foreach ($invoices as $invoice) {
            $outstanding = $this->outstandingFromLoadedRelations($invoice);

            if (Decimal::isPositive($outstanding)) {
                $total = Decimal::add($total, $outstanding, Decimal::MONEY_SCALE);
            }
        }

        return $total;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function openInvoices(
        Customer $customer,
        int $storeId,
        ?int $focusInvoiceId = null,
        ?int $limit = null,
    ): Collection {
        $invoices = Invoice::query()
            ->where('store_id', $storeId)
            ->where('customer_id', $customer->id)
            ->where('type', InvoiceType::Sales)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
            ])
            ->with([
                'receiptAllocations.receipt',
                'childInvoices',
            ])
            ->when($focusInvoiceId !== null, function ($query) use ($focusInvoiceId): void {
                $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$focusInvoiceId]);
            })
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        return $invoices
            ->filter(fn (Invoice $invoice): bool => Decimal::isPositive(
                $this->outstandingFromLoadedRelations($invoice),
            ))
            ->values();
    }

    private function outstandingFromLoadedRelations(Invoice $invoice): string
    {
        $received = '0.00';

        foreach ($invoice->receiptAllocations as $allocation) {
            if ($allocation->receipt?->status === TreasuryDocumentStatus::Confirmed) {
                $received = Decimal::add($received, $allocation->amount, Decimal::MONEY_SCALE);
            }
        }

        $returned = '0.00';

        foreach ($invoice->childInvoices as $return) {
            if ($return->type === InvoiceType::SalesReturn
                && $return->status === InvoiceStatus::Confirmed) {
                $returned = Decimal::add($returned, $return->total, Decimal::MONEY_SCALE);
            }
        }

        $remaining = Decimal::subtract($invoice->total, $received, Decimal::MONEY_SCALE);
        $remaining = Decimal::subtract($remaining, $returned, Decimal::MONEY_SCALE);

        return Decimal::compare($remaining, '0', Decimal::MONEY_SCALE) > 0
            ? $remaining
            : '0.00';
    }
}
