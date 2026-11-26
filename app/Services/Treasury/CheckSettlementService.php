<?php

namespace App\Services\Treasury;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InvoiceType;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Models\AccountingPeriod;
use App\Models\Check;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Support\Decimal;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CheckSettlementService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
    ) {}

    public function collectReceivable(
        Check $check,
        string $settlementDate,
        User $approver,
        ?int $treasuryAccountId = null,
    ): Check {
        return $this->settle($check, $settlementDate, $approver, $treasuryAccountId, CheckType::Receivable);
    }

    public function settlePayable(
        Check $check,
        string $settlementDate,
        User $approver,
        ?int $treasuryAccountId = null,
    ): Check {
        return $this->settle($check, $settlementDate, $approver, $treasuryAccountId, CheckType::Payable);
    }

    public function bounce(
        Check $check,
        string $bounceDate,
        User $approver,
    ): Check {
        return DB::transaction(function () use ($check, $bounceDate, $approver): Check {
            $locked = Check::query()->lockForUpdate()->findOrFail($check->id);
            $treasuryDocument = $this->treasuryDocumentFor($locked);
            $requestPayload = $this->actionPayload(
                $locked,
                $treasuryDocument,
                'check.bounce',
                $bounceDate,
                $approver->id,
            );
            $requestHash = IdempotencyHasher::hash('check.bounce', $requestPayload);

            if ($locked->status === CheckStatus::Bounced) {
                return $this->resolveBounceRetry($locked, $requestHash);
            }

            if ($locked->status !== CheckStatus::Pending) {
                throw new DomainException('فقط چک در انتظار وصول یا خرج قابل برگشت است.');
            }

            if (! $treasuryDocument || $treasuryDocument->status !== TreasuryDocumentStatus::Confirmed) {
                throw new DomainException('سند خزانه تأییدشده برای این چک پیدا نشد.');
            }

            $isReceivable = $locked->type === CheckType::Receivable;
            $partyId = $treasuryDocument instanceof Receipt
                ? $treasuryDocument->customer->party_id
                : $treasuryDocument->supplier->party_id;
            $period = $this->periodFor($treasuryDocument->store_id, $bounceDate);
            $lines = $isReceivable
                ? [
                    [
                        'account_id' => $this->settings->defaultAccountId('accounts_receivable', $locked->store_id),
                        'party_id' => $partyId,
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'برگشت چک دریافتی و ایجاد مطالبه',
                    ],
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_receivable', $locked->store_id),
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'کاهش چک‌های دریافتنی',
                    ],
                ]
                : [
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_payable', $locked->store_id),
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'برگشت چک پرداختنی',
                    ],
                    [
                        'account_id' => $this->settings->defaultAccountId('accounts_payable', $locked->store_id),
                        'party_id' => $partyId,
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'احیای بدهی تأمین‌کننده',
                    ],
                ];
            $entry = $this->posting->post(
                [
                    'store_id' => $treasuryDocument->store_id,
                    'branch_id' => $treasuryDocument->branch_id,
                    'fiscal_year_id' => $period->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $bounceDate,
                    'description' => "برگشت چک {$locked->check_number}",
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
            );

            $locked->markBounced($entry, $requestHash);
            $treasuryDocument->markBounced();

            foreach ($treasuryDocument->allocations as $allocation) {
                $this->refreshInvoiceStatus($allocation->invoice);
            }

            return $locked->refresh();
        }, attempts: 3);
    }

    private function settle(
        Check $check,
        string $settlementDate,
        User $approver,
        ?int $treasuryAccountId,
        CheckType $expectedType,
    ): Check {
        return DB::transaction(function () use (
            $check,
            $settlementDate,
            $approver,
            $treasuryAccountId,
            $expectedType,
        ): Check {
            $locked = Check::query()->lockForUpdate()->findOrFail($check->id);

            if ($locked->type !== $expectedType) {
                throw new DomainException('نوع چک با عملیات درخواستی سازگار نیست.');
            }

            $treasuryDocument = $this->treasuryDocumentFor($locked);
            $requestPayload = $this->actionPayload(
                $locked,
                $treasuryDocument,
                'check.settlement',
                $settlementDate,
                $approver->id,
                $treasuryAccountId,
            );
            $requestHash = IdempotencyHasher::hash('check.settlement', $requestPayload);

            if ($locked->status === CheckStatus::Collected || $locked->status === CheckStatus::Spent) {
                return $this->resolveSettlementRetry($locked, $requestHash, $requestPayload);
            }

            if ($locked->status !== CheckStatus::Pending) {
                throw new DomainException('فقط چک در انتظار قابل وصول یا خرج است.');
            }

            if (! $treasuryDocument || $treasuryDocument->status !== TreasuryDocumentStatus::Confirmed) {
                throw new DomainException('سند خزانه تأییدشده برای این چک پیدا نشد.');
            }

            $treasuryAccount = TreasuryAccount::query()
                ->where('store_id', $treasuryDocument->store_id)
                ->where('branch_id', $treasuryDocument->branch_id)
                ->where('type', TreasuryAccountType::Bank)
                ->where('is_active', true)
                ->when($treasuryAccountId !== null, fn ($query) => $query->whereKey($treasuryAccountId))
                ->orderByDesc('is_default')
                ->lockForUpdate()
                ->first();

            if (! $treasuryAccount) {
                throw new DomainException('حساب بانکی فعال برای تسویه چک پیدا نشد.');
            }

            $period = $this->periodFor($treasuryDocument->store_id, $settlementDate);
            $isReceivable = $expectedType === CheckType::Receivable;
            $lines = $isReceivable
                ? [
                    [
                        'account_id' => $treasuryAccount->gl_account_id,
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'وصول چک دریافتی',
                    ],
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_receivable', $locked->store_id),
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'تسویه چک‌های دریافتنی',
                    ],
                ]
                : [
                    [
                        'account_id' => $this->settings->defaultAccountId('checks_payable', $locked->store_id),
                        'debit' => $locked->amount,
                        'credit' => '0.00',
                        'description' => 'خرج چک پرداختنی',
                    ],
                    [
                        'account_id' => $treasuryAccount->gl_account_id,
                        'debit' => '0.00',
                        'credit' => $locked->amount,
                        'description' => 'کاهش حساب بانکی',
                    ],
                ];
            $entry = $this->posting->post(
                [
                    'store_id' => $treasuryDocument->store_id,
                    'branch_id' => $treasuryDocument->branch_id,
                    'fiscal_year_id' => $period->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $settlementDate,
                    'description' => "تسویه چک {$locked->check_number}",
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
            );

            $locked->markSettled($entry, $treasuryAccount, $requestHash);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function treasuryDocumentFor(Check $check): Receipt|Payment|null
    {
        return $check->type === CheckType::Receivable
            ? $check->receipt()->first()
            : $check->payment()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function actionPayload(
        Check $check,
        Receipt|Payment|null $treasuryDocument,
        string $operation,
        string $actionDate,
        int $actorId,
        ?int $treasuryAccountId = null,
    ): array {
        $allocations = $treasuryDocument
            ? $treasuryDocument->allocations()
                ->orderBy('invoice_id')
                ->get()
                ->map(fn ($allocation): array => [
                    'invoice_id' => (int) $allocation->invoice_id,
                    'amount' => Decimal::normalize($allocation->amount, Decimal::MONEY_SCALE),
                ])
                ->values()
                ->all()
            : [];
        $sourceInvoiceIds = collect($allocations)
            ->pluck('invoice_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'operation' => $operation,
            'check_id' => (int) $check->id,
            'check_document_number' => $check->document_number,
            'check_description' => $check->description,
            'store_id' => (int) $check->store_id,
            'branch_id' => (int) $check->branch_id,
            'fiscal_year_id' => $check->fiscal_year_id === null ? null : (int) $check->fiscal_year_id,
            'check_type' => $check->type->value,
            'customer_id' => $check->customer_id === null ? null : (int) $check->customer_id,
            'supplier_id' => $check->supplier_id === null ? null : (int) $check->supplier_id,
            'check_number' => $check->check_number,
            'bank_name' => $check->bank_name,
            'branch_name' => $check->branch_name,
            'amount' => Decimal::normalize($check->amount, Decimal::MONEY_SCALE),
            'issue_date' => $check->issue_date?->toDateString(),
            'due_date' => $check->due_date->toDateString(),
            'treasury_document_type' => $treasuryDocument === null
                ? null
                : ($treasuryDocument instanceof Receipt ? 'receipt' : 'payment'),
            'treasury_document_id' => $treasuryDocument?->id,
            'treasury_document_number' => $treasuryDocument instanceof Receipt
                ? $treasuryDocument->receipt_number
                : ($treasuryDocument?->payment_number),
            'treasury_document_fiscal_year_id' => $treasuryDocument?->fiscal_year_id,
            'treasury_document_payment_method' => $treasuryDocument?->payment_method->value,
            'treasury_document_reference' => $treasuryDocument?->reference,
            'treasury_document_description' => $treasuryDocument?->description,
            'allocations' => $allocations,
            'source_invoice_ids' => $sourceInvoiceIds,
            'action_date' => CarbonImmutable::parse($actionDate)->toDateString(),
            'actor_id' => $actorId,
            'treasury_account_id' => $treasuryAccountId,
            'entry_description' => $operation === 'check.settlement'
                ? "تسویه چک {$check->check_number}"
                : "برگشت چک {$check->check_number}",
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    private function resolveSettlementRetry(
        Check $check,
        string $requestHash,
        array $requestPayload,
    ): Check {
        if ($check->settlement_request_hash !== null) {
            if (! hash_equals($check->settlement_request_hash, $requestHash)) {
                throw new DomainException('این چک قبلاً با اطلاعات دیگری وصول یا خرج شده است.');
            }

            return $check->refresh();
        }

        $entry = $check->settlementEntry;
        $treasuryDocument = $this->treasuryDocumentFor($check);

        if (! $entry
            || $entry->source_type !== $check->getMorphClass()
            || (int) $entry->source_id !== (int) $check->id) {
            throw new DomainException('این چک قبلاً با اطلاعات دیگری وصول یا خرج شده است.');
        }

        $persistedPayload = $this->actionPayload(
            $check,
            $treasuryDocument,
            'check.settlement',
            $entry->entry_date->toDateString(),
            (int) ($entry->posted_by ?? $entry->created_by),
            $check->settlement_treasury_account_id === null
                ? null
                : (int) $check->settlement_treasury_account_id,
        );

        if ($requestPayload['treasury_account_id'] === null) {
            $persistedPayload['treasury_account_id'] = null;
        }

        $persistedPayload['entry_description'] = $entry->description;

        if (! hash_equals(
            IdempotencyHasher::hash('check.settlement', $persistedPayload),
            $requestHash,
        )) {
            throw new DomainException('این چک قبلاً با اطلاعات دیگری وصول یا خرج شده است.');
        }

        return $check->refresh();
    }

    private function resolveBounceRetry(
        Check $check,
        string $requestHash,
    ): Check {
        if ($check->bounce_request_hash !== null) {
            if (! hash_equals($check->bounce_request_hash, $requestHash)) {
                throw new DomainException('این چک قبلاً با اطلاعات دیگری برگشت خورده است.');
            }

            return $check->refresh();
        }

        $entry = $check->bounceEntry;
        $treasuryDocument = $this->treasuryDocumentFor($check);

        if (! $entry
            || $entry->source_type !== $check->getMorphClass()
            || (int) $entry->source_id !== (int) $check->id) {
            throw new DomainException('این چک قبلاً با اطلاعات دیگری برگشت خورده است.');
        }

        $persistedPayload = $this->actionPayload(
            $check,
            $treasuryDocument,
            'check.bounce',
            $entry->entry_date->toDateString(),
            (int) ($entry->posted_by ?? $entry->created_by),
        );

        $persistedPayload['entry_description'] = $entry->description;

        if (! hash_equals(
            IdempotencyHasher::hash('check.bounce', $persistedPayload),
            $requestHash,
        )) {
            throw new DomainException('این چک قبلاً با اطلاعات دیگری برگشت خورده است.');
        }

        return $check->refresh();
    }

    private function periodFor(int $storeId, string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->where('store_id', $storeId)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if (! $period) {
            throw new DomainException('برای تاریخ عملیات چک، دوره مالی باز یا تعریف‌شده‌ای وجود ندارد.');
        }

        return $period;
    }

    private function refreshInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->type !== InvoiceType::Sales && $invoice->type !== InvoiceType::Purchase) {
            return;
        }

        $invoice->syncSettlementStatus();
    }
}
