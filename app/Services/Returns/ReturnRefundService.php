<?php

namespace App\Services\Returns;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JournalEntryKind;
use App\Models\Invoice;
use App\Models\ReturnRefund;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Audit\AuditLogService;
use App\Services\Events\DomainEventService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReturnRefundService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AccountingPeriodLockService $periods,
        private readonly AuditLogService $audit,
        private readonly DomainEventService $events,
    ) {}

    public function refund(
        Invoice $returnInvoice,
        int $treasuryAccountId,
        string $refundDate,
        string|int|float $amount,
        User $actor,
        string $idempotencyKey,
    ): ReturnRefund {
        $amount = Decimal::normalize($amount, Decimal::MONEY_SCALE);
        $refundDate = CarbonImmutable::parse($refundDate)->toDateString();
        $requestHash = hash('sha256', json_encode([
            'source_invoice_id' => $returnInvoice->id,
            'treasury_account_id' => $treasuryAccountId,
            'refund_date' => $refundDate,
            'amount' => $amount,
            'created_by' => $actor->id,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use (
                $returnInvoice,
                $treasuryAccountId,
                $refundDate,
                $amount,
                $actor,
                $idempotencyKey,
                $requestHash,
            ): ReturnRefund {
                $existing = ReturnRefund::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new DomainException('کلید تکرارپذیری refund قبلاً برای درخواست متفاوتی استفاده شده است.');
                    }

                    return $existing->load('journalEntry');
                }

                $return = Invoice::query()
                    ->with(['customer.party', 'supplier.party'])
                    ->lockForUpdate()
                    ->findOrFail($returnInvoice->id);

                if ($return->status !== InvoiceStatus::Confirmed
                    || ! in_array($return->type, [InvoiceType::SalesReturn, InvoiceType::PurchaseReturn], true)) {
                    throw new DomainException('فقط مرجوعی تأییدشده قابل refund است.');
                }

                if (! Decimal::isPositive($amount)) {
                    throw new DomainException('مبلغ refund باید بزرگ‌تر از صفر باشد.');
                }

                $direction = $return->type === InvoiceType::SalesReturn ? 'customer' : 'supplier';
                $party = $direction === 'customer' ? $return->customer?->party : $return->supplier?->party;

                if (! $party || ! $party->is_active || (int) $party->store_id !== (int) $return->store_id) {
                    throw new DomainException('طرف‌حساب مرجوعی معتبر و فعال نیست.');
                }

                $refunded = (string) ReturnRefund::query()
                    ->where('source_invoice_id', $return->id)
                    ->where('direction', $direction)
                    ->where('status', 'posted')
                    ->lockForUpdate()
                    ->sum('amount');
                $remainingRefundable = Decimal::subtract($return->total, $refunded, Decimal::MONEY_SCALE);

                if (Decimal::compare($amount, $remainingRefundable, Decimal::MONEY_SCALE) > 0) {
                    throw new DomainException('مبلغ refund از مانده قابل برگشت بیشتر است.');
                }

                $treasury = TreasuryAccount::query()
                    ->whereKey($treasuryAccountId)
                    ->where('store_id', $return->store_id)
                    ->where('branch_id', $return->branch_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $treasury || ! $treasury->glAccount?->is_active) {
                    throw new DomainException('حساب خزانهٔ refund فعال و متعلق به همان شعبه نیست.');
                }

                $context = $this->periods->lockOpenPeriod(
                    (int) $return->store_id,
                    (int) $return->branch_id,
                    (int) $return->fiscal_year_id,
                    $refundDate,
                );
                $refund = ReturnRefund::query()->create([
                    'store_id' => $return->store_id,
                    'branch_id' => $return->branch_id,
                    'fiscal_year_id' => $return->fiscal_year_id,
                    'source_invoice_id' => $return->id,
                    'original_invoice_id' => $return->parent_invoice_id,
                    'party_id' => $party->id,
                    'direction' => $direction,
                    'amount' => $amount,
                    'refund_date' => $refundDate,
                    'treasury_account_id' => $treasury->id,
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'created_by' => $actor->id,
                ]);
                $lines = $direction === 'customer'
                    ? [
                        [
                            'account_id' => $this->settings->defaultAccountId('accounts_receivable', $return->store_id),
                            'party_id' => $party->id,
                            'debit' => $amount,
                            'credit' => '0.00',
                            'description' => 'پرداخت refund مشتری',
                        ],
                        [
                            'account_id' => $treasury->gl_account_id,
                            'debit' => '0.00',
                            'credit' => $amount,
                            'description' => 'کاهش وجه نقد یا بانک',
                        ],
                    ]
                    : [
                        [
                            'account_id' => $treasury->gl_account_id,
                            'debit' => $amount,
                            'credit' => '0.00',
                            'description' => 'دریافت refund تأمین‌کننده',
                        ],
                        [
                            'account_id' => $this->settings->defaultAccountId('accounts_payable', $return->store_id),
                            'party_id' => $party->id,
                            'debit' => '0.00',
                            'credit' => $amount,
                            'description' => 'تسویه اعتبار تأمین‌کننده',
                        ],
                    ];
                $entry = $this->posting->post(
                    [
                        'store_id' => $return->store_id,
                        'branch_id' => $return->branch_id,
                        'fiscal_year_id' => $return->fiscal_year_id,
                        'accounting_period_id' => $context['period']->id,
                        'entry_kind' => JournalEntryKind::System,
                        'entry_date' => $refundDate,
                        'description' => 'ثبت refund مرجوعی',
                        'source_type' => $refund->getMorphClass(),
                        'source_id' => $refund->id,
                        'created_by' => $actor->id,
                    ],
                    $lines,
                    $actor,
                );
                $refund->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id])->save();
                $this->audit->record('return_refund_posted', $refund);
                $this->events->record('return.refunded', $refund, [
                    'refund_id' => $refund->id,
                    'source_invoice_id' => $refund->source_invoice_id,
                    'amount' => $refund->amount,
                    'direction' => $refund->direction,
                ]);

                return $refund->refresh()->load('journalEntry');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $existing = ReturnRefund::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new DomainException('کلید تکرارپذیری refund قبلاً برای درخواست متفاوتی استفاده شده است.');
            }

            return $existing->load('journalEntry');
        }
    }
}
