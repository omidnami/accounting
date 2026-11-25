<?php

namespace App\Services\Purchasing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\ReturnRefund;
use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;

class SupplierPayableService
{
    public function supplierOutstanding(Supplier $supplier): string
    {
        $invoices = Invoice::query()
            ->where('supplier_id', $supplier->id)
            ->where('type', InvoiceType::Purchase)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->with([
                'paymentAllocations.payment',
                'childInvoices',
                'supplierRefunds',
            ])
            ->orderBy('id')
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
            $paid = '0.00';

            foreach (PaymentAllocation::query()
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->where('payment_allocations.invoice_id', $invoice->id)
                ->where('payments.status', 'confirmed')
                ->orderBy('payment_allocations.id')
                ->lockForUpdate()
                ->get(['payment_allocations.amount']) as $allocation) {
                $paid = Decimal::add($paid, $allocation->amount, Decimal::MONEY_SCALE);
            }

            $returned = '0.00';

            foreach (Invoice::query()
                ->where('parent_invoice_id', $invoice->id)
                ->where('type', InvoiceType::PurchaseReturn)
                ->where('status', InvoiceStatus::Confirmed)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['total']) as $return) {
                $returned = Decimal::add($returned, $return->total, Decimal::MONEY_SCALE);
            }

            $remaining = Decimal::subtract($invoice->total, $paid, Decimal::MONEY_SCALE);
            $remaining = Decimal::subtract($remaining, $returned, Decimal::MONEY_SCALE);
            $refunded = (string) ReturnRefund::query()
                ->where('original_invoice_id', $invoice->id)
                ->where('direction', 'supplier')
                ->where('status', 'posted')
                ->lockForUpdate()
                ->sum('amount');
            $remaining = Decimal::add($remaining, $refunded, Decimal::MONEY_SCALE);

            return $remaining;
        }

        $invoice->load([
            'paymentAllocations.payment',
            'childInvoices',
            'supplierRefunds',
        ]);

        return $this->outstandingFromLoadedRelations($invoice);
    }

    public function storeOutstanding(int $storeId, ?int $branchId = null): string
    {
        $invoices = Invoice::query()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('type', InvoiceType::Purchase)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->with([
                'paymentAllocations.payment',
                'childInvoices',
                'supplierRefunds',
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
        Supplier $supplier,
        int $storeId,
        ?int $focusInvoiceId = null,
        ?int $branchId = null,
    ): Collection {
        return Invoice::query()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('supplier_id', $supplier->id)
            ->where('type', InvoiceType::Purchase)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Reversed,
            ])
            ->with([
                'paymentAllocations.payment',
                'childInvoices',
                'supplierRefunds',
            ])
            ->when($focusInvoiceId !== null, function ($query) use ($focusInvoiceId): void {
                $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$focusInvoiceId]);
            })
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => Decimal::isPositive(
                $this->outstandingFromLoadedRelations($invoice),
            ))
            ->values();
    }

    private function outstandingFromLoadedRelations(Invoice $invoice): string
    {
        $paid = '0.00';

        foreach ($invoice->paymentAllocations as $allocation) {
            if ($allocation->payment?->status === TreasuryDocumentStatus::Confirmed) {
                $paid = Decimal::add($paid, $allocation->amount, Decimal::MONEY_SCALE);
            }
        }

        $returned = '0.00';

        foreach ($invoice->childInvoices as $return) {
            if ($return->type === InvoiceType::PurchaseReturn
                && $return->status === InvoiceStatus::Confirmed) {
                $returned = Decimal::add($returned, $return->total, Decimal::MONEY_SCALE);
            }
        }

        $remaining = Decimal::subtract($invoice->total, $paid, Decimal::MONEY_SCALE);
        $remaining = Decimal::subtract($remaining, $returned, Decimal::MONEY_SCALE);
        $refunded = '0.00';

        foreach ($invoice->supplierRefunds as $refund) {
            if ($refund->status === 'posted') {
                $refunded = Decimal::add($refunded, $refund->amount, Decimal::MONEY_SCALE);
            }
        }

        return Decimal::add($remaining, $refunded, Decimal::MONEY_SCALE);
    }
}
