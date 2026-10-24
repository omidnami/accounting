<?php

namespace App\Services\Pos;

use App\Enums\CheckType;
use App\Enums\PaymentMethod;
use App\Models\FiscalYear;
use App\Models\PosTender;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Treasury\CheckRegistrationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PosCheckResolutionService
{
    public function __construct(
        private readonly CheckRegistrationService $checks,
        private readonly CustomerReceiptService $receipts,
        private readonly AuditLogService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function resolve(PosTender $tender, User $user, array $data): PosTender
    {
        if (! $user->canManageChecks() || ! $user->canManageReceipts()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($tender, $user, $data): PosTender {
            $tender = PosTender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();
            if ($tender->method !== 'check') {
                throw new DomainException('این مورد اعلام چک نیست.');
            }
            if ($tender->resolved_at !== null) {
                return $tender;
            }
            $invoice = $tender->sale->invoice;
            $fiscalYear = FiscalYear::query()->where('store_id', $invoice->store_id)
                ->whereDate('starts_on', '<=', $data['receipt_date'])
                ->whereDate('ends_on', '>=', $data['receipt_date'])->first();
            if (! $fiscalYear) {
                throw new DomainException('برای تاریخ دریافت سال مالی تعریف نشده است.');
            }
            $check = $this->checks->register(
                storeId: $invoice->store_id,
                branchId: $invoice->branch_id,
                fiscalYearId: $fiscalYear->id,
                type: CheckType::Receivable,
                partyProfileId: $invoice->customer_id,
                checkNumber: $data['check_number'],
                bankName: $data['bank_name'],
                branchName: null,
                amount: $tender->amount,
                issueDate: $invoice->invoice_date->toDateString(),
                dueDate: $data['due_date'],
                creator: $user,
                idempotencyKey: 'pos:check:'.$tender->id,
                description: 'چک فاکتور '.$invoice->invoice_number,
            );
            $receipt = $this->receipts->receive(
                storeId: $invoice->store_id,
                branchId: $invoice->branch_id,
                fiscalYearId: $fiscalYear->id,
                customerId: $invoice->customer_id,
                receiptDate: $data['receipt_date'],
                paymentMethod: PaymentMethod::Check,
                allocations: [['invoice_id' => $invoice->id, 'amount' => $tender->amount]],
                approver: $user,
                idempotencyKey: 'pos:check-receipt:'.$tender->id,
                checkId: $check->id,
            );
            $tender->update([
                'check_received' => true,
                'check_number' => $data['check_number'],
                'bank_name' => $data['bank_name'],
                'due_date' => $data['due_date'],
                'receipt_id' => $receipt->id,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);
            $this->audit->record('pos.check_registered', $invoice, newValues: ['tender_id' => $tender->id, 'receipt_id' => $receipt->id]);

            return $tender;
        }, attempts: 3);
    }
}
