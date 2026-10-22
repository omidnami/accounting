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

        return DB::transaction(function () use ($store, $year, $range): FiscalYear {
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
                'is_current' => true,
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
