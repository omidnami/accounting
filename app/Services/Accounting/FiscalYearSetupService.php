<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\CalendarType;
use App\Enums\DocumentType;
use App\Enums\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\FiscalYear;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class FiscalYearSetupService
{
    /** @var array<string, string> */
    private const SEQUENCE_PREFIXES = [
        'journal_entry' => 'JE-',
        'sales_invoice' => 'SI-',
        'sales_return' => 'SR-',
        'purchase_invoice' => 'PI-',
        'purchase_return' => 'PR-',
        'quotation' => 'QT-',
        'receipt' => 'RC-',
        'payment' => 'PY-',
        'check' => 'CK-',
    ];

    public function __construct(
        private readonly JalaliDateService $dates,
    ) {}

    public function setupJalaliYear(Store $store, int $year): FiscalYear
    {
        $range = $this->dates->fiscalYearRange($year);
        $today = CarbonImmutable::now('Asia/Tehran')->startOfDay();
        $isCurrent = ! $today->lessThan($range['starts_on'])
            && ! $today->greaterThan($range['ends_on']);

        return DB::transaction(function () use ($store, $year, $range, $isCurrent): FiscalYear {
            $lockedStore = Store::query()
                ->whereKey($store->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $overlap = FiscalYear::query()
                ->where('store_id', $lockedStore->id)
                ->where('starts_on', '<=', $range['ends_on'])
                ->where('ends_on', '>=', $range['starts_on'])
                ->first();

            if ($overlap) {
                throw new DomainException('بازه سال مالی با یک سال مالی موجود هم‌پوشانی دارد.');
            }

            $fiscalYear = FiscalYear::query()->create([
                'store_id' => $lockedStore->id,
                'title' => (string) $year,
                'calendar' => CalendarType::Jalali,
                'starts_on' => $range['starts_on'],
                'ends_on' => $range['ends_on'],
                'status' => FiscalYearStatus::Open,
                'is_current' => $isCurrent,
            ]);

            foreach ($this->dates->periods($year) as $period) {
                AccountingPeriod::query()->create([
                    'store_id' => $lockedStore->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    ...$period,
                    'status' => AccountingPeriodStatus::Open,
                ]);
            }

            $this->ensureSequences($fiscalYear);

            return $fiscalYear->load(['periods', 'documentSequences']);
        });
    }

    public function setCurrent(Store $store, FiscalYear $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($store, $fiscalYear): FiscalYear {
            $lockedStore = Store::query()
                ->whereKey($store->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedYear = FiscalYear::query()
                ->whereKey($fiscalYear->getKey())
                ->where('store_id', $lockedStore->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedYear) {
                throw new DomainException('سال مالی انتخاب‌شده متعلق به این فروشگاه نیست.');
            }

            if ($lockedYear->status !== FiscalYearStatus::Open) {
                throw new DomainException('فقط سال مالی باز می‌تواند سال جاری شود.');
            }

            FiscalYear::query()
                ->where('store_id', $lockedStore->id)
                ->whereKeyNot($lockedYear->id)
                ->update(['is_current' => false]);

            $lockedYear->forceFill(['is_current' => true])->save();

            return $lockedYear->refresh()->load(['periods', 'documentSequences']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function overviewForStore(Store $store): array
    {
        $years = FiscalYear::query()
            ->where('store_id', $store->id)
            ->with(['periods' => fn ($query) => $query->orderBy('period_number')])
            ->orderByDesc('starts_on')
            ->get();
        $currentYear = $years->firstWhere('is_current', true);
        $periods = $years->flatMap->periods;
        $suggestedYear = $this->dates->currentYear();
        $usedYears = $years->pluck('title')->map(fn (string $title): string => $title)->all();

        while (in_array((string) $suggestedYear, $usedYears, true)) {
            $suggestedYear++;
        }

        return [
            'years' => $years->map(fn (FiscalYear $year): array => [
                'id' => $year->id,
                'title' => $year->title,
                'calendar' => $year->calendar->value,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'status' => $year->status->value,
                'is_current' => $year->is_current,
                'can_set_current' => $year->status === FiscalYearStatus::Open && ! $year->is_current,
                'periods' => $year->periods
                    ->sortBy('period_number')
                    ->map(fn (AccountingPeriod $period): array => [
                        'id' => $period->id,
                        'name' => $period->name,
                        'period_number' => $period->period_number,
                        'starts_on' => $period->starts_on->toDateString(),
                        'ends_on' => $period->ends_on->toDateString(),
                        'status' => $period->status->value,
                    ])
                    ->values(),
            ])->values(),
            'summary' => [
                'years' => $years->count(),
                'current_title' => $currentYear?->title,
                'open_periods' => $periods
                    ->where('status', AccountingPeriodStatus::Open)
                    ->count(),
                'closed_periods' => $periods
                    ->whereIn('status', [
                        AccountingPeriodStatus::SoftClosed,
                        AccountingPeriodStatus::Closed,
                    ])
                    ->count(),
            ],
            'suggested_year' => $suggestedYear,
        ];
    }

    public function ensureSequences(FiscalYear $fiscalYear): void
    {
        $branch = Branch::query()
            ->where('store_id', $fiscalYear->store_id)
            ->where('is_default', true)
            ->firstOrFail();

        foreach (DocumentType::cases() as $type) {
            DocumentSequence::query()->firstOrCreate(
                [
                    'store_id' => $fiscalYear->store_id,
                    'branch_id' => $branch->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'document_type' => $type,
                ],
                [
                    'prefix' => self::SEQUENCE_PREFIXES[$type->value].$fiscalYear->title.'-',
                    'next_number' => 1,
                    'padding' => 6,
                    'is_active' => true,
                ],
            );
        }
    }
}
