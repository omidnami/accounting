<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvoiceDeletionService
{
    public function deleteDraft(
        Invoice $invoice,
        InvoiceType $type,
        int $storeId,
        int $branchId,
    ): void {
        DB::transaction(function () use ($invoice, $type, $storeId, $branchId): void {
            $locked = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->type !== $type
                || (int) $locked->store_id !== $storeId
                || (int) $locked->branch_id !== $branchId) {
                abort(404);
            }

            if ($locked->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط پیش‌نویس فاکتور قابل حذف است.');
            }

            $hasDependencies = JournalEntry::query()
                ->where('source_type', $locked->getMorphClass())
                ->where('source_id', $locked->id)
                ->exists()
                || InventoryMovement::query()
                    ->where('source_type', $locked->getMorphClass())
                    ->where('source_id', $locked->id)
                    ->exists()
                || $locked->childInvoices()->exists()
                || $locked->receiptAllocations()->exists()
                || $locked->paymentAllocations()->exists();

            if ($hasDependencies) {
                throw new DomainException(
                    'این فاکتور سابقهٔ مالی یا انباری دارد و حذف پیش‌نویس آن مجاز نیست.',
                );
            }

            $locked->delete();
        }, attempts: 3);
    }
}
