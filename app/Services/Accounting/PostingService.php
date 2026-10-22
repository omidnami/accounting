<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\DocumentType;
use App\Enums\FiscalYearStatus;
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

        $entry->loadMissing(['accountingPeriod', 'fiscalYear']);

        if (! $entry->accountingPeriod) {
            throw new DomainException('برای ثبت سند، دوره مالی الزامی است.');
        }

        if ($entry->accountingPeriod->status !== AccountingPeriodStatus::Open) {
            throw new DomainException('ثبت سند در دوره بسته یا نیمه‌بسته مجاز نیست.');
        }

        if ($entry->fiscalYear && $entry->fiscalYear->status !== FiscalYearStatus::Open) {
            throw new DomainException('ثبت سند در سال مالی غیرفعال مجاز نیست.');
        }

        return $entry->finalizePosting($approver);
    }
}
