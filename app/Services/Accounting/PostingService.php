<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PostingService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(array $attributes, array $lines): JournalEntry
    {
        return JournalEntry::createBalanced([
            ...$attributes,
            'status' => JournalEntryStatus::Draft,
        ], $lines);
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

        return DB::transaction(fn (): JournalEntry => $entry->post());
    }
}
