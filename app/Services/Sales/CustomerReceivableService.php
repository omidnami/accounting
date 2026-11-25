<?php

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ReceiptAllocation;
use App\Models\ReturnRefund;
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
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->with([
                'receiptAllocations.receipt',
                'childInvoices',
                'customerRefunds',
            ])
            ->orderBy('id')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get();
        $total = '0.00';

        foreach ($invoices as $invoice) {
            $outstanding = $this->outstandingFromLoadedRelations($invoice);

            $total = Decimal::add($total, $outstanding, Decimal::MONEY_SCALE);
        }

        return $total;
    }

    public function outstandingForInvoice(
        Invoice $invoice,
        bool $lockForUpdate = false,
    ): string {
        if ($lockForUpdate) {
            $received = '0.00';

            foreach (ReceiptAllocation::query()
                ->join('receipts', 'receipts.id', '=', 'receipt_allocations.receipt_id')
                ->where('receipt_allocations.invoice_id', $invoice->id)
                ->where('receipts.status', 'confirmed')
                ->orderBy('receipt_allocations.id')
                ->lockForUpdate()
                ->get(['receipt_allocations.amount']) as $allocation) {
                $received = Decimal::add($received, $allocation->amount, Decimal::MONEY_SCALE);
            }

            $returned = '0.00';

            foreach (Invoice::query()
                ->where('parent_invoice_id', $invoice->id)
                ->where('type', InvoiceType::SalesReturn)
                ->where('status', InvoiceStatus::Confirmed)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['total']) as $return) {
                $returned = Decimal::add($returned, $return->total, Decimal::MONEY_SCALE);
            }

            $remaining = Decimal::subtract($invoice->total, $received, Decimal::MONEY_SCALE);
            $remaining = Decimal::subtract($remaining, $returned, Decimal::MONEY_SCALE);
            $refunded = (string) ReturnRefund::query()
                ->where('original_invoice_id', $invoice->id)
                ->where('direction', 'customer')
                ->where('status', 'posted')
                ->lockForUpdate()
                ->sum('amount');
            $remaining = Decimal::add($remaining, $refunded, Decimal::MONEY_SCALE);

            return $remaining;
        }

        $invoice->load([
            'receiptAllocations.receipt',
            'childInvoices',
            'customerRefunds',
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
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->with([
                'receiptAllocations.receipt',
                'childInvoices',
                'customerRefunds',
            ])
            ->get();
        $total = '0.00';

        foreach ($invoices as $invoice) {
            $outstanding = $this->outstandingFromLoadedRelations($invoice);

            $total = Decimal::add($total, $outstanding, Decimal::MONEY_SCALE);
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
        ?int $branchId = null,
    ): Collection {
        $invoices = Invoice::query()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('customer_id', $customer->id)
            ->where('type', InvoiceType::Sales)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->with([
                'receiptAllocations.receipt',
                'childInvoices',
                'customerRefunds',
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
        $refunded = '0.00';

        foreach ($invoice->customerRefunds as $refund) {
            if ($refund->status === 'posted') {
                $refunded = Decimal::add($refunded, $refund->amount, Decimal::MONEY_SCALE);
            }
        }

        return Decimal::add($remaining, $refunded, Decimal::MONEY_SCALE);
    }
}
