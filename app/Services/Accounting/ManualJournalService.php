<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ManualJournalService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{entries: LengthAwarePaginator<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function paginate(Store $store, array $filters): array
    {
        $base = JournalEntry::query()
            ->where('store_id', $store->id)
            ->whereIn('entry_kind', [
                JournalEntryKind::Manual,
                JournalEntryKind::Opening,
            ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $kind = $filters['entry_kind'] ?? null;
        $status = $filters['status'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $filtered = clone $base;

        if ($search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $filtered->where(function (Builder $query) use ($term): void {
                $query->where('entry_number', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        $entries = $filtered
            ->when($kind !== null, fn (Builder $query) => $query->where('entry_kind', $kind))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($from !== null, fn (Builder $query) => $query->whereDate('entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('entry_date', '<=', $to))
            ->with(['creator:id,name', 'lines'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (JournalEntry $entry): array => $this->summaryRow($entry));

        return [
            'entries' => $entries,
            'summary' => [
                'total' => (clone $base)->count(),
                'drafts' => (clone $base)->where('status', JournalEntryStatus::Draft)->count(),
                'posted' => (clone $base)->where('status', JournalEntryStatus::Posted)->count(),
                'openings' => (clone $base)->where('entry_kind', JournalEntryKind::Opening)->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(Store $store): array
    {
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->where('status', FiscalYearStatus::Open)
            ->first();
        $inventoryCode = $this->settings->defaultAccountCode('inventory');
        $accounts = Account::query()
            ->where('store_id', $store->id)
            ->where('level', AccountLevel::Subsidiary)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($inventoryCode): array {
                $isManualAllowed = $account->code !== $inventoryCode;
                $isOpeningAllowed = $isManualAllowed && in_array($account->type, [
                    AccountType::Asset,
                    AccountType::Liability,
                    AccountType::Equity,
                ], true);

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'normal_balance' => $account->normal_balance->value,
                    'requires_party' => $account->requires_party,
                    'can_use_manual' => $isManualAllowed,
                    'can_use_opening' => $isOpeningAllowed,
                ];
            })
            ->values();
        $today = CarbonImmutable::now('Asia/Tehran')->startOfDay();
        $defaultDate = $today->toDateString();

        if ($fiscalYear) {
            if ($today->lessThan($fiscalYear->starts_on)) {
                $defaultDate = $fiscalYear->starts_on->toDateString();
            } elseif ($today->greaterThan($fiscalYear->ends_on)) {
                $defaultDate = $fiscalYear->ends_on->toDateString();
            }
        }

        return [
            'accounts' => $accounts,
            'parties' => Party::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'fiscal_year' => $fiscalYear ? [
                'id' => $fiscalYear->id,
                'title' => $fiscalYear->title,
                'starts_on' => $fiscalYear->starts_on->toDateString(),
                'ends_on' => $fiscalYear->ends_on->toDateString(),
            ] : null,
            'default_date' => $defaultDate,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(Store $store, array $data, User $author): JournalEntry
    {
        return DB::transaction(function () use ($store, $data, $author): JournalEntry {
            $kind = $this->manualKind((string) $data['entry_kind']);
            $context = $this->resolveContext($store, (string) $data['entry_date']);
            $lines = $this->normalizeLines($data['lines']);
            $this->validateLines(
                $store,
                $context['branch'],
                $context['fiscal_year'],
                $lines,
                $kind,
                (string) $data['entry_date'],
                null,
            );

            return $this->posting->createDraft(
                [
                    'store_id' => $store->id,
                    'branch_id' => $context['branch']->id,
                    'fiscal_year_id' => $context['fiscal_year']->id,
                    'accounting_period_id' => $context['period']->id,
                    'entry_kind' => $kind,
                    'entry_date' => (string) $data['entry_date'],
                    'description' => trim((string) $data['description']),
                    'created_by' => $author->id,
                ],
                $lines,
            );
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(
        Store $store,
        JournalEntry $entry,
        array $data,
    ): JournalEntry {
        return DB::transaction(function () use ($store, $entry, $data): JournalEntry {
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->entry_kind->isManual()) {
                throw new DomainException('سند انتخاب‌شده برای ویرایش دستی معتبر نیست.');
            }

            if ($locked->status !== JournalEntryStatus::Draft) {
                throw new DomainException('فقط سند دستی پیش‌نویس قابل ویرایش است.');
            }

            $kind = $this->manualKind((string) $data['entry_kind']);

            if ($kind !== $locked->entry_kind) {
                throw new DomainException('نوع سند پس از ایجاد قابل تغییر نیست.');
            }

            $context = $this->resolveContext($store, (string) $data['entry_date']);
            $lines = $this->normalizeLines($data['lines']);
            $this->validateLines(
                $store,
                $context['branch'],
                $context['fiscal_year'],
                $lines,
                $kind,
                (string) $data['entry_date'],
                $locked,
            );

            $locked->forceFill([
                'branch_id' => $context['branch']->id,
                'fiscal_year_id' => $context['fiscal_year']->id,
                'accounting_period_id' => $context['period']->id,
                'entry_date' => (string) $data['entry_date'],
                'description' => trim((string) $data['description']),
            ])->save();
            $locked->lines()->delete();

            foreach ($lines as $index => $line) {
                $locked->lines()->create([
                    ...$line,
                    'sort_order' => $index + 1,
                ]);
            }

            $locked->assertBalanced();

            return $locked->refresh()->load([
                'lines.account',
                'lines.party',
                'creator',
                'fiscalYear',
                'accountingPeriod',
            ]);
        }, attempts: 3);
    }

    public function post(Store $store, JournalEntry $entry, User $approver): JournalEntry
    {
        return DB::transaction(function () use ($store, $entry, $approver): JournalEntry {
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->entry_kind->isManual()) {
                throw new DomainException('سند انتخاب‌شده برای ثبت دستی معتبر نیست.');
            }

            $context = $this->resolveContext($store, $locked->entry_date->toDateString());
            $lines = $locked->lines()->get()->map(fn ($line): array => [
                'account_id' => $line->account_id,
                'party_id' => $line->party_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
            ])->all();
            $this->validateLines(
                $store,
                $context['branch'],
                $context['fiscal_year'],
                $lines,
                $locked->entry_kind,
                $locked->entry_date->toDateString(),
                $locked,
            );

            return $this->posting->approveAndPost($locked, $approver);
        }, attempts: 3);
    }

    public function deleteDraft(Store $store, JournalEntry $entry): void
    {
        DB::transaction(function () use ($store, $entry): void {
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->entry_kind->isManual()) {
                throw new DomainException('سند انتخاب‌شده برای حذف دستی معتبر نیست.');
            }

            if ($locked->status !== JournalEntryStatus::Draft) {
                throw new DomainException('فقط سند دستی پیش‌نویس قابل حذف است.');
            }

            $locked->lines()->delete();
            $locked->delete();
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function detailForStore(Store $store, JournalEntry $entry): array
    {
        if ((int) $entry->store_id !== (int) $store->id || ! $entry->entry_kind->isManual()) {
            throw new DomainException('سند درخواستی در دسترس این فروشگاه نیست.');
        }

        $entry->load([
            'lines.account',
            'lines.party',
            'creator:id,name',
            'branch:id,name',
            'fiscalYear:id,title',
            'accountingPeriod:id,name',
        ]);

        return [
            ...$this->summaryRow($entry),
            'can_edit' => $entry->status === JournalEntryStatus::Draft,
            'can_post' => $entry->status === JournalEntryStatus::Draft,
            'branch' => ['name' => $entry->branch->name],
            'fiscal_year' => ['title' => $entry->fiscalYear->title],
            'accounting_period' => ['name' => $entry->accountingPeriod->name],
            'lines' => $entry->lines->map(fn ($line): array => [
                'id' => $line->id,
                'account' => [
                    'code' => $line->account->code,
                    'name' => $line->account->name,
                ],
                'party' => $line->party ? [
                    'code' => $line->party->code,
                    'name' => $line->party->name,
                ] : null,
                'debit' => Decimal::normalize($line->debit, Decimal::MONEY_SCALE),
                'credit' => Decimal::normalize($line->credit, Decimal::MONEY_SCALE),
                'description' => $line->description,
            ])->values(),
        ];
    }

    /**
     * @return array{entry_kind: string, entry_date: string, description: string, lines: array<int, array{account_id: int, party_id: int|null, debit: string, credit: string, description: string|null}>}
     */
    public function editablePayload(Store $store, JournalEntry $entry): array
    {
        if ((int) $entry->store_id !== (int) $store->id
            || ! $entry->entry_kind->isManual()
            || $entry->status !== JournalEntryStatus::Draft) {
            throw new DomainException('فقط سند دستی پیش‌نویس قابل ویرایش است.');
        }

        $entry->load(['lines.account']);

        return [
            'id' => $entry->id,
            'entry_kind' => $entry->entry_kind->value,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'lines' => $entry->lines->map(fn ($line): array => [
                'account_id' => $line->account_id,
                'party_id' => $line->party_id,
                'debit' => Decimal::normalize($line->debit, Decimal::MONEY_SCALE),
                'credit' => Decimal::normalize($line->credit, Decimal::MONEY_SCALE),
                'description' => $line->description,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{branch: Branch, fiscal_year: FiscalYear, period: AccountingPeriod}
     */
    private function resolveContext(Store $store, string $entryDate): array
    {
        $date = CarbonImmutable::parse($entryDate, 'Asia/Tehran')->startOfDay();
        $branch = Branch::query()
            ->where('store_id', $store->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            throw new DomainException('برای ثبت سند، شعبه پیش‌فرض فعال الزامی است.');
        }

        $fiscalYear = FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->where('status', FiscalYearStatus::Open)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->lockForUpdate()
            ->first();

        if (! $fiscalYear) {
            throw new DomainException('برای تاریخ سند، سال مالی جاری باز پیدا نشد.');
        }

        $period = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', AccountingPeriodStatus::Open)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->lockForUpdate()
            ->first();

        if (! $period) {
            throw new DomainException('برای تاریخ سند، دوره مالی باز پیدا نشد.');
        }

        return [
            'branch' => $branch,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
        ];
    }

    private function manualKind(string $kind): JournalEntryKind
    {
        $entryKind = JournalEntryKind::tryFrom($kind);

        if (! $entryKind?->isManual()) {
            throw new DomainException('نوع سند باید دستی یا مانده افتتاحیه باشد.');
        }

        return $entryKind;
    }

    /**
     * @return array<int, array{account_id: int, party_id: int|null, debit: string, credit: string, description: string|null}>
     */
    private function normalizeLines(mixed $lines): array
    {
        if (! is_array($lines) || count($lines) < 2 || count($lines) > 100) {
            throw new DomainException('سند دستی باید بین دو تا صد ردیف داشته باشد.');
        }

        return collect($lines)
            ->values()
            ->map(function (mixed $line): array {
                if (! is_array($line)) {
                    throw new DomainException('ردیف سند معتبر نیست.');
                }

                return [
                    'account_id' => (int) ($line['account_id'] ?? 0),
                    'party_id' => isset($line['party_id']) && $line['party_id'] !== ''
                        ? (int) $line['party_id']
                        : null,
                    'debit' => Decimal::normalize($line['debit'] ?? 0, Decimal::MONEY_SCALE),
                    'credit' => Decimal::normalize($line['credit'] ?? 0, Decimal::MONEY_SCALE),
                    'description' => isset($line['description']) && trim((string) $line['description']) !== ''
                        ? trim((string) $line['description'])
                        : null,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array{account_id: int, party_id: int|null, debit: string, credit: string, description: string|null}>  $lines
     */
    private function validateLines(
        Store $store,
        Branch $branch,
        FiscalYear $fiscalYear,
        array $lines,
        JournalEntryKind $kind,
        string $entryDate,
        ?JournalEntry $entry,
    ): void {
        $accounts = Account::query()
            ->whereIn('id', collect($lines)->pluck('account_id')->unique())
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('level', AccountLevel::Subsidiary)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== collect($lines)->pluck('account_id')->unique()->count()) {
            throw new DomainException('همه حساب‌های سند باید معین، فعال و متعلق به فروشگاه باشند.');
        }

        $inventoryCode = $this->settings->defaultAccountCode('inventory');
        $debitTotal = '0.00';
        $creditTotal = '0.00';

        foreach ($lines as $line) {
            $account = $accounts->get($line['account_id']);

            if (! $account) {
                throw new DomainException('حساب یکی از ردیف‌های سند معتبر نیست.');
            }

            if ($account->code === $inventoryCode) {
                throw new DomainException('تغییر موجودی باید از مسیر سند و گردش کنترل‌شده انبار انجام شود.');
            }

            if ($kind === JournalEntryKind::Opening
                && ! in_array($account->type, [
                    AccountType::Asset,
                    AccountType::Liability,
                    AccountType::Equity,
                ], true)) {
                throw new DomainException('مانده افتتاحیه فقط برای حساب‌های دارایی، بدهی و سرمایه مجاز است.');
            }

            if ($account->requires_party && $line['party_id'] === null) {
                throw new DomainException('برای حساب دریافتنی یا پرداختنی، طرف‌حساب را انتخاب کنید.');
            }

            $debitPositive = Decimal::isPositive($line['debit']);
            $creditPositive = Decimal::isPositive($line['credit']);

            if ($debitPositive === $creditPositive) {
                throw new DomainException('هر ردیف باید دقیقاً یکی از مبالغ بدهکار یا بستانکار را داشته باشد.');
            }

            $debitTotal = Decimal::add($debitTotal, $line['debit'], Decimal::MONEY_SCALE);
            $creditTotal = Decimal::add($creditTotal, $line['credit'], Decimal::MONEY_SCALE);
        }

        if (Decimal::compare($debitTotal, $creditTotal, Decimal::MONEY_SCALE) !== 0) {
            throw new DomainException('جمع بدهکار و بستانکار سند باید کاملاً برابر باشد.');
        }

        $partyIds = collect($lines)->pluck('party_id')->filter()->unique();

        if ($partyIds->isNotEmpty()) {
            $activeParties = Party::query()
                ->whereIn('id', $partyIds)
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->pluck('id');

            if ($activeParties->count() !== $partyIds->count()) {
                throw new DomainException('همه طرف‌حساب‌های سند باید فعال و متعلق به فروشگاه باشند.');
            }
        }

        if ($kind !== JournalEntryKind::Opening) {
            return;
        }

        if (! CarbonImmutable::parse($fiscalYear->starts_on)
            ->isSameDay(CarbonImmutable::parse($entryDate))) {
            throw new DomainException('تاریخ مانده افتتاحیه باید اولین روز سال مالی باشد.');
        }

        $otherOpening = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('entry_kind', JournalEntryKind::Opening)
            ->when($entry !== null, fn (Builder $query) => $query->whereKeyNot($entry->id))
            ->lockForUpdate()
            ->exists();

        if ($otherOpening) {
            throw new DomainException('برای این شعبه و سال مالی قبلاً سند مانده افتتاحیه ایجاد شده است.');
        }

        $postedActivity = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', JournalEntryStatus::Posted)
            ->when($entry !== null, fn (Builder $query) => $query->whereKeyNot($entry->id))
            ->exists();

        if ($postedActivity) {
            throw new DomainException('پس از ثبت گردش مالی، ایجاد مانده افتتاحیه مجاز نیست.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryRow(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_kind' => $entry->entry_kind->value,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'status' => $entry->status->value,
            'total_debit' => $entry->totalDebit(),
            'total_credit' => $entry->totalCredit(),
            'creator' => $entry->creator?->name,
            'created_at' => $entry->created_at?->toDateTimeString(),
        ];
    }
}
