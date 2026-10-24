<?php

namespace App\Services\Pos;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PosCheckoutService
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly CustomerReceiptService $receipts,
        private readonly AuditLogService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function checkout(PosSession $session, User $user, array $data): PosSale
    {
        if (! $user->canManageSales() || (int) $session->user_id !== (int) $user->id) {
            throw new AuthorizationException;
        }

        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($session, $user, $data, $hash): PosSale {
            $session = PosSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $existing = PosSale::query()->where('submission_token', $data['submission_token'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->pos_session_id !== (int) $session->id || ! hash_equals($existing->request_hash, $hash)) {
                    throw new DomainException('این درخواست قبلاً با اطلاعات دیگری ثبت شده است.');
                }

                return $existing->load([
                    'invoice' => fn ($query) => $query->lockForUpdate(),
                    'invoice.items' => fn ($query) => $query->lockForUpdate(),
                    'invoice.customer.party',
                    'tenders' => fn ($query) => $query->lockForUpdate(),
                ]);
            }
            if ($session->closed_at !== null) {
                throw new DomainException('شیفت بسته شده است؛ شیفت جدید باز کنید.');
            }
            $date = today()->toDateString();
            $fiscalYear = FiscalYear::query()->where('store_id', $session->store_id)
                ->where('is_current', true)->firstOrFail();
            $pending = '0.00';
            foreach ($data['tenders'] as $tender) {
                if (in_array($tender['method'], ['credit', 'check'], true)) {
                    $pending = Decimal::add($pending, $tender['amount'], 2);
                }
            }

            $customerId = $data['customer_id'] ?? null;
            if (! $customerId) {
                if (Decimal::isPositive($pending)) {
                    throw new DomainException('برای فروش نسیه یا چکی مشتری مشخص انتخاب کنید.');
                }
                Store::query()->whereKey($session->store_id)->lockForUpdate()->firstOrFail();
                $party = Party::query()->firstOrCreate(
                    ['store_id' => $session->store_id, 'code' => 'POS-CASH'],
                    ['name' => 'مشتری نقدی صندوق', 'is_active' => true],
                );
                $customerId = Customer::query()->firstOrCreate(['party_id' => $party->id], ['credit_limit' => '0.00', 'is_active' => true])->id;
            }

            $sale = PosSale::query()->create([
                'pos_session_id' => $session->id,
                'submission_token' => $data['submission_token'],
                'request_hash' => $hash,
            ]);
            $invoice = $this->sales->createDraft([
                'store_id' => $session->store_id,
                'branch_id' => $session->branch_id,
                'fiscal_year_id' => $fiscalYear->id,
                'customer_id' => $customerId,
                'invoice_date' => $date,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ], $data['items'], 'pos:'.$data['submission_token']);
            $sale->update(['invoice_id' => $invoice->id]);
            $sum = '0.00';
            foreach ($data['tenders'] as $tender) {
                if (! in_array($tender['method'], ['cash', 'card', 'check', 'credit'], true) || ! Decimal::isPositive($tender['amount'])) {
                    throw new DomainException('روش یا مبلغ پرداخت نامعتبر است.');
                }
                if (in_array($tender['method'], ['cash', 'card'], true) && ! ($tender['confirmed'] ?? false)) {
                    throw new DomainException('دریافت نقدی یا کارت را پس از انجام پرداخت تأیید کنید.');
                }
                if ($tender['method'] === 'credit' && empty($data['due_date'])) {
                    throw new DomainException('موعد پرداخت نسیه را مشخص کنید.');
                }
                $sum = Decimal::add($sum, $tender['amount'], 2);
                $sale->tenders()->create([
                    'method' => $tender['method'],
                    'amount' => $tender['amount'],
                    'reference' => $tender['reference'] ?? null,
                    'check_received' => $tender['method'] === 'check' && ($tender['check_received'] ?? false),
                    'check_number' => $tender['method'] === 'check' ? ($tender['check_number'] ?? null) : null,
                    'bank_name' => $tender['method'] === 'check' ? ($tender['bank_name'] ?? null) : null,
                    'due_date' => $tender['method'] === 'check' ? ($tender['due_date'] ?? null) : null,
                    'notes' => $tender['notes'] ?? null,
                ]);
            }
            if (Decimal::compare($sum, $invoice->total, 2) !== 0) {
                throw new DomainException('جمع نقد، کارت، چک و نسیه باید برابر مبلغ فاکتور باشد.');
            }
            $context = new PosPostingContext($sale->id);
            $this->sales->confirm($invoice, $session->warehouse_id, $user, $context);
            foreach ($sale->tenders()->whereIn('method', ['cash', 'card'])->get() as $tender) {
                $accountId = $tender->method === 'cash' ? $session->cash_account_id : $session->card_account_id;
                if ($accountId === null) {
                    throw new DomainException('برای این شیفت کارت‌خوان تعیین نشده است.');
                }
                $this->receipts->receive(
                    storeId: $session->store_id,
                    branchId: $session->branch_id,
                    fiscalYearId: $fiscalYear->id,
                    customerId: $invoice->customer_id,
                    receiptDate: $date,
                    paymentMethod: PaymentMethod::from($tender->method),
                    allocations: [['invoice_id' => $invoice->id, 'amount' => $tender->amount]],
                    approver: $user,
                    idempotencyKey: 'pos:tender:'.$tender->id,
                    treasuryAccountId: $accountId,
                    reference: $tender->reference,
                    description: 'دریافت صندوق فروش',
                    posContext: $context,
                    posTenderId: $tender->id,
                );
                $tender->update(['resolved_at' => now(), 'resolved_by' => $user->id]);
            }
            $sale->update(['completed_at' => now()]);
            $this->audit->record('pos.sale', $invoice);

            return $sale->load(['invoice.items', 'invoice.customer.party', 'tenders']);
        }, attempts: 3);
    }
}
