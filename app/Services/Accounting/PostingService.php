<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\DocumentType;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PostingService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(array $attributes, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($attributes, $lines): JournalEntry {
            unset($attributes['status'], $attributes['posted_at']);

            if (empty($attributes['entry_number'])) {
                $attributes['entry_number'] = $this->documentNumbers->next(
                    (int) $attributes['store_id'],
                    (int) $attributes['branch_id'],
                    (int) $attributes['fiscal_year_id'],
                    DocumentType::JournalEntry,
                );
            }

            $entry = JournalEntry::query()->create($attributes);

            foreach (array_values($lines) as $index => $line) {
                $entry->lines()->create([
                    ...$line,
                    'sort_order' => $line['sort_order'] ?? $index + 1,
                ]);
            }

            $entry->assertBalanced();

            return $entry->refresh()->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function post(array $attributes, array $lines, User $approver): JournalEntry
    {
        return DB::transaction(function () use ($attributes, $lines, $approver): JournalEntry {
            $entry = $this->createDraft($attributes, $lines);

            return $this->approveAndPost($entry, $approver);
        });
    }

    public function approveAndPost(JournalEntry $entry, User $approver): JournalEntry
    {
        if (! $approver->canApproveJournalEntries()) {
            throw new AuthorizationException('تأیید و ثبت سند فقط برای مدیر و حسابدار مجاز است.');
        }

        return DB::transaction(function () use ($entry, $approver): JournalEntry {
            $entry->loadMissing(['accountingPeriod', 'fiscalYear']);

            if (! $entry->accountingPeriod || ! $entry->fiscalYear) {
                throw new DomainException('برای ثبت سند، دوره و سال مالی الزامی هستند.');
            }

            $fiscalYear = FiscalYear::query()
                ->whereKey($entry->fiscal_year_id)
                ->lockForUpdate()
                ->first();
            $period = AccountingPeriod::query()
                ->whereKey($entry->accounting_period_id)
                ->lockForUpdate()
                ->first();

            if (! $period || $period->status !== AccountingPeriodStatus::Open) {
                throw new DomainException('ثبت سند در دوره بسته یا نیمه‌بسته مجاز نیست.');
            }

            if (! $fiscalYear || $fiscalYear->status !== FiscalYearStatus::Open) {
                throw new DomainException('ثبت سند در سال مالی غیرفعال مجاز نیست.');
            }

            return $entry->finalizePosting($approver);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function postReversal(
        JournalEntry $original,
        User $approver,
        array $attributes = [],
    ): JournalEntry {
        if (! $approver->canApproveJournalEntries()) {
            throw new AuthorizationException('ثبت سند معکوس فقط برای مدیر و حسابدار مجاز است.');
        }

        return DB::transaction(function () use ($original, $approver, $attributes): JournalEntry {
            $lockedOriginal = JournalEntry::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOriginal->status !== JournalEntryStatus::Posted) {
                throw new DomainException('فقط سند ثبت‌شده قابل معکوس‌سازی است.');
            }

            $existing = $lockedOriginal->reversal()->lockForUpdate()->first();

            if ($existing) {
                return $existing->load('lines');
            }

            $lines = $lockedOriginal->lines()
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($line): array => [
                    'account_id' => $line->account_id,
                    'party_id' => $line->party_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                ])
                ->all();

            if ($lines === []) {
                throw new DomainException('سند اصلی برای معکوس‌سازی ردیف ندارد.');
            }

            $attributes = [
                ...$attributes,
                'store_id' => $lockedOriginal->store_id,
                'branch_id' => $lockedOriginal->branch_id,
                'fiscal_year_id' => $lockedOriginal->fiscal_year_id,
                'accounting_period_id' => $lockedOriginal->accounting_period_id,
                'entry_kind' => JournalEntryKind::Reversal,
                'entry_date' => $lockedOriginal->entry_date->toDateString(),
                'reversal_of_id' => $lockedOriginal->id,
                'source_type' => $lockedOriginal->source_type,
                'source_id' => $lockedOriginal->source_id,
                'created_by' => $attributes['created_by'] ?? $approver->id,
            ];

            return $this->post($attributes, $lines, $approver);
        }, attempts: 3);
    }
}
