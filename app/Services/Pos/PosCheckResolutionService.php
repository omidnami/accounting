<?php

namespace App\Services\Pos;

use App\Enums\CheckType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\PosSale;
use App\Models\PosTender;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\CustomerReceivableService;
use App\Services\Treasury\CheckRegistrationService;
use App\Support\Decimal;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PosCheckResolutionService
{
    public function __construct(
        private readonly CheckRegistrationService $checks,
        private readonly CustomerReceiptService $receipts,
        private readonly CustomerReceivableService $receivables,
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

            $sale = PosSale::query()
                ->with(['invoice', 'session'])
                ->lockForUpdate()
                ->findOrFail($tender->pos_sale_id);
            $invoice = $sale->invoice;

            if (! $invoice || ! $sale->session) {
                throw new DomainException('فاکتور فروش این چک معتبر یا قابل تسویه نیست.');
            }

            $requestPayload = $this->resolutionPayload($tender, $sale, $invoice, $user, $data);
            $requestHash = IdempotencyHasher::hash('pos.check.resolution', $requestPayload);

            if ($tender->resolved_at !== null) {
                return $this->resolveIdempotentTender($tender, $sale, $invoice, $requestHash);
            }

            if ($invoice->type !== InvoiceType::Sales
                || ! in_array($invoice->status, [InvoiceStatus::Confirmed, InvoiceStatus::PartiallyPaid], true)
                || (int) $invoice->store_id !== (int) $sale->session->store_id
                || (int) $invoice->branch_id !== (int) $sale->session->branch_id
                || Decimal::compare(
                    $this->receivables->outstandingForInvoice($invoice, true),
                    $tender->amount,
                    Decimal::MONEY_SCALE,
                ) < 0) {
                throw new DomainException('فاکتور فروش این چک معتبر یا قابل تسویه نیست.');
            }

            $fiscalYear = FiscalYear::query()->where('store_id', $invoice->store_id)
                ->whereDate('starts_on', '<=', $data['receipt_date'])
                ->whereDate('ends_on', '>=', $data['receipt_date'])->first();
            if (! $fiscalYear) {
                throw new DomainException('برای تاریخ دریافت سال مالی تعریف نشده است.');
            }
            $checkNumber = trim((string) $data['check_number']);
            $bankName = trim((string) $data['bank_name']);
            $check = $this->checks->register(
                storeId: $invoice->store_id,
                branchId: $invoice->branch_id,
                fiscalYearId: $fiscalYear->id,
                type: CheckType::Receivable,
                partyProfileId: $invoice->customer_id,
                checkNumber: $checkNumber,
                bankName: $bankName,
                branchName: null,
                amount: $tender->amount,
                issueDate: $invoice->invoice_date->toDateString(),
                dueDate: $data['due_date'],
                creator: $user,
                idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_URL, 'accounting:pos-check:'.$tender->id)->toString(),
                description: 'چک فاکتور '.$invoice->invoice_number,
                sourceInvoiceIds: [$invoice->id],
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
                'check_number' => $checkNumber,
                'bank_name' => $bankName,
                'due_date' => $data['due_date'],
                'receipt_id' => $receipt->id,
                'resolution_request_hash' => $requestHash,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);
            $this->audit->record('pos.check_registered', $invoice, newValues: ['tender_id' => $tender->id, 'receipt_id' => $receipt->id]);

            return $tender;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolutionPayload(
        PosTender $tender,
        PosSale $sale,
        Invoice $invoice,
        User $user,
        array $data,
    ): array {
        return [
            'tender_id' => (int) $tender->id,
            'pos_sale_id' => (int) $sale->id,
            'pos_session_id' => (int) $sale->pos_session_id,
            'store_id' => (int) $invoice->store_id,
            'branch_id' => (int) $invoice->branch_id,
            'fiscal_year_id' => (int) $invoice->fiscal_year_id,
            'invoice_id' => (int) $invoice->id,
            'customer_id' => (int) $invoice->customer_id,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'method' => $tender->method,
            'amount' => Decimal::normalize($tender->amount, Decimal::MONEY_SCALE),
            'actor_id' => (int) $user->id,
            'check_number' => trim((string) $data['check_number']),
            'bank_name' => trim((string) $data['bank_name']),
            'due_date' => CarbonImmutable::parse($data['due_date'])->toDateString(),
            'receipt_date' => CarbonImmutable::parse($data['receipt_date'])->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function persistedResolutionPayload(
        PosTender $tender,
        PosSale $sale,
        Invoice $invoice,
    ): array {
        $tender->loadMissing('receipt');
        $receipt = $tender->receipt;

        if ($tender->resolved_by === null || ! $receipt) {
            throw new DomainException('این درخواست قبلاً با اطلاعات دیگری ثبت شده است.');
        }

        return [
            'tender_id' => (int) $tender->id,
            'pos_sale_id' => (int) $sale->id,
            'pos_session_id' => (int) $sale->pos_session_id,
            'store_id' => (int) $invoice->store_id,
            'branch_id' => (int) $invoice->branch_id,
            'fiscal_year_id' => (int) $invoice->fiscal_year_id,
            'invoice_id' => (int) $invoice->id,
            'customer_id' => (int) $invoice->customer_id,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'method' => $tender->method,
            'amount' => Decimal::normalize($tender->amount, Decimal::MONEY_SCALE),
            'actor_id' => (int) $tender->resolved_by,
            'check_number' => trim((string) $tender->check_number),
            'bank_name' => trim((string) $tender->bank_name),
            'due_date' => $tender->due_date?->toDateString(),
            'receipt_date' => $receipt->receipt_date->toDateString(),
        ];
    }

    private function resolveIdempotentTender(
        PosTender $tender,
        PosSale $sale,
        Invoice $invoice,
        string $requestHash,
    ): PosTender {
        $matches = $tender->resolution_request_hash !== null
            ? hash_equals($tender->resolution_request_hash, $requestHash)
            : hash_equals(
                IdempotencyHasher::hash(
                    'pos.check.resolution',
                    $this->persistedResolutionPayload($tender, $sale, $invoice),
                ),
                $requestHash,
            );

        if (! $matches) {
            throw new DomainException('این درخواست قبلاً با اطلاعات دیگری ثبت شده است.');
        }

        return $tender;
    }
}
