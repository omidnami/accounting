<?php

namespace App\Services\Purchasing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Invoice;
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
            ])
            ->with([
                'paymentAllocations.payment',
                'childInvoices',
            ])
            ->orderBy('id')
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
            'paymentAllocations.payment',
            'childInvoices',
        ]);

        return $this->outstandingFromLoadedRelations($invoice);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function openInvoices(
        Supplier $supplier,
        int $storeId,
        ?int $focusInvoiceId = null,
    ): Collection {
        return Invoice::query()
            ->where('store_id', $storeId)
            ->where('supplier_id', $supplier->id)
            ->where('type', InvoiceType::Purchase)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
            ])
            ->with([
                'paymentAllocations.payment',
                'childInvoices',
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

        return Decimal::compare($remaining, '0', Decimal::MONEY_SCALE) > 0
            ? $remaining
            : '0.00';
    }
}
