<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryKind;
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
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class PeriodClosingService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly YearEndClosingService $yearEndClosing,
    ) {}

    public function closePeriod(
        Store $store,
        AccountingPeriod $period,
        AccountingPeriodStatus $targetStatus,
        User $actor,
        ?string $reason = null,
    ): AccountingPeriod {
        if (! $actor->canCloseAccountingPeriods()) {
            throw new DomainException('بستن دوره مالی فقط برای مدیر و حسابدار مجاز است.');
        }

        if (! in_array($targetStatus, [
            AccountingPeriodStatus::SoftClosed,
            AccountingPeriodStatus::Closed,
        ], true)) {
            throw new DomainException('وضعیت مقصد بستن دوره معتبر نیست.');
        }

        return DB::transaction(function () use (
            $store,
            $period,
            $targetStatus,
            $actor,
            $reason,
        ): AccountingPeriod {
            [$lockedStore, $fiscalYear, $lockedPeriod] = $this->lockScope($store, $period);
            $this->assertYearCanChange($fiscalYear);

            $canUpgradeSoftClose = $lockedPeriod->status === AccountingPeriodStatus::SoftClosed
                && $targetStatus === AccountingPeriodStatus::Closed;

            if ($lockedPeriod->status !== AccountingPeriodStatus::Open && ! $canUpgradeSoftClose) {
                throw new DomainException('دوره انتخاب‌شده در وضعیت قابل بستن نیست.');
            }

            $blockers = $this->blockingDocuments(
                $lockedStore,
                $fiscalYear,
                $lockedPeriod,
            );

            if ($blockers > 0) {
                throw new DomainException(
                    "بستن دوره ممکن نیست؛ {$blockers} سند باز باید ابتدا تعیین تکلیف شود.",
                );
            }

            $oldValues = [
                'status' => $lockedPeriod->status->value,
                'closed_by' => $lockedPeriod->closed_by,
                'closed_at' => $lockedPeriod->closed_at?->toDateTimeString(),
            ];
            $lockedPeriod->transitionStatus($targetStatus, $actor->id);
            $this->auditLogs->record(
                'period_closed',
                $lockedPeriod,
                $oldValues,
                [
                    'status' => $targetStatus->value,
                    'closed_by' => $actor->id,
                    'closed_at' => $lockedPeriod->closed_at?->toDateTimeString(),
                    'reason' => $reason,
                ],
                $reason ?: "دوره {$lockedPeriod->name} بسته شد.",
            );

            return $lockedPeriod->refresh();
        }, attempts: 3);
    }

    public function reopenPeriod(
        Store $store,
        AccountingPeriod $period,
        User $actor,
        string $reason,
    ): AccountingPeriod {
        if (! $actor->canReopenAccountingPeriods()) {
            throw new DomainException('بازگشایی دوره مالی فقط برای مدیر مجاز است.');
        }

        if (trim($reason) === '') {
            throw new DomainException('دلیل بازگشایی دوره را وارد کنید.');
        }

        return DB::transaction(function () use ($store, $period, $reason): AccountingPeriod {
            [, $fiscalYear, $lockedPeriod] = $this->lockScope($store, $period);

            if ($fiscalYear->status !== FiscalYearStatus::Open) {
                throw new DomainException('برای بازگشایی دوره، ابتدا سال مالی باید باز باشد.');
            }

            if ($lockedPeriod->status === AccountingPeriodStatus::Open) {
                throw new DomainException('دوره انتخاب‌شده از قبل باز است.');
            }

            $oldValues = [
                'status' => $lockedPeriod->status->value,
                'closed_by' => $lockedPeriod->closed_by,
                'closed_at' => $lockedPeriod->closed_at?->toDateTimeString(),
            ];
            $lockedPeriod->transitionStatus(AccountingPeriodStatus::Open);
            $this->auditLogs->record(
                'period_reopened',
                $lockedPeriod,
                $oldValues,
                [
                    'status' => AccountingPeriodStatus::Open->value,
                    'closed_by' => null,
                    'closed_at' => null,
                    'reason' => trim($reason),
                ],
                trim($reason),
            );

            return $lockedPeriod->refresh();
        }, attempts: 3);
    }

    public function closeFiscalYear(
        Store $store,
        FiscalYear $fiscalYear,
        User $actor,
        string $reason,
    ): FiscalYear {
        if (! $actor->canCloseFiscalYears()) {
            throw new DomainException('بستن سال مالی فقط برای مدیر مجاز است.');
        }

        if (trim($reason) === '') {
            throw new DomainException('دلیل بستن سال مالی را وارد کنید.');
        }

        return DB::transaction(function () use ($store, $fiscalYear, $actor, $reason): FiscalYear {
            $lockedStore = Store::query()
                ->whereKey($store->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedYear = FiscalYear::query()
                ->whereKey($fiscalYear->id)
                ->where('store_id', $lockedStore->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedYear) {
                throw new DomainException('سال مالی متعلق به این فروشگاه نیست.');
            }

            if ($lockedYear->status !== FiscalYearStatus::Open) {
                throw new DomainException('فقط سال مالی باز قابل بستن است.');
            }

            $today = CarbonImmutable::now('Asia/Tehran')->startOfDay();

            if (! $lockedYear->ends_on->lessThan($today)) {
                throw new DomainException('سال مالی فقط پس از پایان بازه آن قابل بستن است.');
            }

            $periods = AccountingPeriod::query()
                ->where('fiscal_year_id', $lockedYear->id)
                ->orderBy('period_number')
                ->lockForUpdate()
                ->get();

            if ($periods->count() !== 12) {
                throw new DomainException('سال مالی برای بستن باید دقیقاً دوازده دوره داشته باشد.');
            }

            $expectedStart = $lockedYear->starts_on;

            foreach ($periods as $period) {
                if (! $period->starts_on->isSameDay($expectedStart)) {
                    throw new DomainException('دوره‌های سال مالی باید پیوسته و بدون فاصله باشند.');
                }

                $expectedStart = $period->ends_on->addDay();
            }

            if (! $expectedStart->subDay()->isSameDay($lockedYear->ends_on)) {
                throw new DomainException('دوره‌های سال مالی باید کل بازه سال را پوشش دهند.');
            }

            if ($periods->contains(
                fn (AccountingPeriod $period): bool => $period->status !== AccountingPeriodStatus::Closed,
            )) {
                throw new DomainException('برای بستن سال، همه دوره‌ها باید ابتدا بسته نهایی شوند.');
            }

            $blockers = $this->blockingDocuments($lockedStore, $lockedYear);

            if ($blockers > 0) {
                throw new DomainException(
                    "بستن سال ممکن نیست؛ {$blockers} سند باز باید ابتدا تعیین تکلیف شود.",
                );
            }

            $nextStart = $lockedYear->ends_on->addDay()->toDateString();
            $successor = FiscalYear::query()
                ->where('store_id', $lockedStore->id)
                ->whereDate('starts_on', $nextStart)
                ->lockForUpdate()
                ->first();
            $nextYear = null;

            if ($lockedYear->is_current) {
                if (! $successor || $successor->status !== FiscalYearStatus::Open) {
                    throw new DomainException(
                        'برای بستن سال جاری، سال مالی بعدی باز باید از قبل ایجاد شده باشد.',
                    );
                }

                $nextYear = $successor;
            }

            if ($successor && JournalEntry::query()
                ->where('store_id', $lockedStore->id)
                ->where('fiscal_year_id', $successor->id)
                ->where('entry_kind', JournalEntryKind::Opening)
                ->whereIn('status', [
                    JournalEntryStatus::Draft,
                    JournalEntryStatus::Posted,
                ])
                ->exists()) {
                throw new DomainException('سال بعد سند مانده افتتاحیه دستی دارد و مانده‌ها از دفتر سال جاری منتقل می‌شوند.');
            }

            $oldValues = [
                'status' => $lockedYear->status->value,
                'is_current' => $lockedYear->is_current,
                'closed_by' => $lockedYear->closed_by,
                'closed_at' => $lockedYear->closed_at?->toDateTimeString(),
            ];
            $closingEntries = $this->yearEndClosing->createClosingEntries(
                $lockedStore,
                $lockedYear,
                $actor,
                $reason,
            );
            $lockedYear->transitionStatus(
                FiscalYearStatus::Closed,
                $actor->id,
                false,
            );

            if ($nextYear) {
                $nextYear->forceFill(['is_current' => true])->save();
            }

            $this->auditLogs->record(
                'fiscal_year_closed',
                $lockedYear,
                $oldValues,
                [
                    'status' => FiscalYearStatus::Closed->value,
                    'is_current' => false,
                    'closed_by' => $actor->id,
                    'closed_at' => $lockedYear->closed_at?->toDateTimeString(),
                    'next_fiscal_year_id' => $nextYear?->id,
                    'closing_entry_ids' => $closingEntries->pluck('id')->all(),
                    'reason' => trim($reason),
                ],
                trim($reason),
            );

            return $lockedYear->refresh();
        }, attempts: 3);
    }

    public function reopenFiscalYear(
        Store $store,
        FiscalYear $fiscalYear,
        User $actor,
        string $reason,
    ): FiscalYear {
        if (! $actor->canReopenFiscalYears()) {
            throw new DomainException('بازگشایی سال مالی فقط برای مدیر مجاز است.');
        }

        if (trim($reason) === '') {
            throw new DomainException('دلیل بازگشایی سال مالی را وارد کنید.');
        }

        return DB::transaction(function () use ($store, $fiscalYear, $actor, $reason): FiscalYear {
            $lockedStore = Store::query()
                ->whereKey($store->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedYear = FiscalYear::query()
                ->whereKey($fiscalYear->id)
                ->where('store_id', $lockedStore->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedYear) {
                throw new DomainException('سال مالی متعلق به این فروشگاه نیست.');
            }

            if ($lockedYear->status !== FiscalYearStatus::Closed) {
                throw new DomainException('فقط سال مالی بسته قابل بازگشایی است.');
            }

            $oldValues = [
                'status' => $lockedYear->status->value,
                'closed_by' => $lockedYear->closed_by,
                'closed_at' => $lockedYear->closed_at?->toDateTimeString(),
            ];
            $lockedYear->transitionStatus(FiscalYearStatus::Open);
            $reversals = $this->yearEndClosing->reverseClosingEntries(
                $lockedStore,
                $lockedYear,
                $actor,
                $reason,
            );
            $this->auditLogs->record(
                'fiscal_year_reopened',
                $lockedYear,
                $oldValues,
                [
                    'status' => FiscalYearStatus::Open->value,
                    'closed_by' => null,
                    'closed_at' => null,
                    'reversal_entry_ids' => $reversals->pluck('id')->all(),
                    'reason' => trim($reason),
                ],
                trim($reason),
            );

            return $lockedYear->refresh();
        }, attempts: 3);
    }

    /**
     * @return array{0: Store, 1: FiscalYear, 2: AccountingPeriod}
     */
    private function lockScope(Store $store, AccountingPeriod $period): array
    {
        $lockedStore = Store::query()
            ->whereKey($store->id)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedYear = FiscalYear::query()
            ->whereKey($period->fiscal_year_id)
            ->where('store_id', $lockedStore->id)
            ->lockForUpdate()
            ->first();
        $lockedPeriod = AccountingPeriod::query()
            ->whereKey($period->id)
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('store_id', $lockedStore->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedYear || ! $lockedPeriod) {
            throw new DomainException('دوره و سال مالی باید متعلق به این فروشگاه باشند.');
        }

        return [$lockedStore, $lockedYear, $lockedPeriod];
    }

    private function assertYearCanChange(FiscalYear $fiscalYear): void
    {
        if ($fiscalYear->status !== FiscalYearStatus::Open) {
            throw new DomainException('برای تغییر دوره، سال مالی باید باز باشد.');
        }
    }

    private function blockingDocuments(
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
