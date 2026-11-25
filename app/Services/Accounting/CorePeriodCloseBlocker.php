<?php

namespace App\Services\Accounting;

use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\StockCountStatus;
use App\Enums\TreasuryDocumentStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\StockCount;
use App\Models\Store;
use App\Services\Accounting\Contracts\PeriodCloseBlocker;

class CorePeriodCloseBlocker implements PeriodCloseBlocker
{
    public function count(
        Store $store,
        FiscalYear $fiscalYear,
        ?AccountingPeriod $period = null,
    ): int {
        $journalQuery = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', JournalEntryStatus::Draft)
            ->when(
                $period !== null,
                fn ($query) => $query->where('accounting_period_id', $period->id),
            );
        $invoiceQuery = Invoice::query()
            ->where('store_id', $store->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', InvoiceStatus::Draft)
            ->when($period !== null, fn ($query) => $query
                ->whereDate('invoice_date', '>=', $period->starts_on)
                ->whereDate('invoice_date', '<=', $period->ends_on));
        $receiptQuery = Receipt::query()
            ->where('store_id', $store->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', TreasuryDocumentStatus::Pending)
            ->when($period !== null, fn ($query) => $query
                ->whereDate('receipt_date', '>=', $period->starts_on)
                ->whereDate('receipt_date', '<=', $period->ends_on));
        $paymentQuery = Payment::query()
            ->where('store_id', $store->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', TreasuryDocumentStatus::Pending)
            ->when($period !== null, fn ($query) => $query
                ->whereDate('payment_date', '>=', $period->starts_on)
                ->whereDate('payment_date', '<=', $period->ends_on));
        $stockCountQuery = StockCount::query()
            ->where('store_id', $store->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', StockCountStatus::Draft)
            ->when(
                $period !== null,
                fn ($query) => $query->where('accounting_period_id', $period->id),
            );

        return $journalQuery->count()
            + $invoiceQuery->count()
            + $receiptQuery->count()
            + $paymentQuery->count()
            + $stockCountQuery->count();
    }
}
