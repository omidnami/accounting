<?php

namespace App\Http\Controllers;

use App\Enums\AccountingPeriodStatus;
use App\Http\Requests\CloseAccountingPeriodRequest;
use App\Http\Requests\FiscalYearClosingRequest;
use App\Http\Requests\ReopenAccountingPeriodRequest;
use App\Http\Requests\StoreFiscalYearRequest;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\FiscalYearSetupService;
use App\Services\Accounting\PeriodClosingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FiscalYearController extends Controller
{
    public function index(
        Request $request,
        FiscalYearSetupService $fiscalYears,
    ): Response {
        abort_unless($request->user()?->canCloseAccountingPeriods(), 403);

        $user = $request->user();

        return Inertia::render('fiscal-years/Index', [
            ...$fiscalYears->overviewForStore($this->mainStore()),
            'permissions' => [
                'manage_years' => $user->canManageFiscalYears(),
                'close_periods' => $user->canCloseAccountingPeriods(),
                'reopen_periods' => $user->canReopenAccountingPeriods(),
                'close_year' => $user->canCloseFiscalYears(),
                'reopen_year' => $user->canReopenFiscalYears(),
            ],
        ]);
    }

    public function store(
        StoreFiscalYearRequest $request,
        FiscalYearSetupService $fiscalYears,
    ): RedirectResponse {
        try {
            $fiscalYear = $fiscalYears->setupJalaliYear(
                $this->mainStore(),
                (int) $request->validated('year'),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'year' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "سال مالی {$fiscalYear->title} با ۱۲ دوره ایجاد شد.",
        ]);

        return to_route('fiscal-years.index');
    }

    public function closePeriod(
        CloseAccountingPeriodRequest $request,
        AccountingPeriod $accountingPeriod,
        PeriodClosingService $periods,
    ): RedirectResponse {
        try {
            $period = $periods->closePeriod(
                $this->mainStore(),
                $accountingPeriod,
                AccountingPeriodStatus::from($request->validated('status')),
                $request->user(),
                $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'period' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "دوره {$period->name} بسته شد.",
        ]);

        return to_route('fiscal-years.index');
    }

    public function reopenPeriod(
        ReopenAccountingPeriodRequest $request,
        AccountingPeriod $accountingPeriod,
        PeriodClosingService $periods,
    ): RedirectResponse {
        try {
            $period = $periods->reopenPeriod(
                $this->mainStore(),
                $accountingPeriod,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'period' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "دوره {$period->name} بازگشایی شد.",
        ]);

        return to_route('fiscal-years.index');
    }

    public function closeYear(
        FiscalYearClosingRequest $request,
        FiscalYear $fiscalYear,
        PeriodClosingService $periods,
    ): RedirectResponse {
        try {
            $year = $periods->closeFiscalYear(
                $this->mainStore(),
                $fiscalYear,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'fiscal_year' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "سال مالی {$year->title} بسته شد.",
        ]);

        return to_route('fiscal-years.index');
    }

    public function reopenYear(
        FiscalYearClosingRequest $request,
        FiscalYear $fiscalYear,
        PeriodClosingService $periods,
    ): RedirectResponse {
        try {
            $year = $periods->reopenFiscalYear(
                $this->mainStore(),
                $fiscalYear,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'fiscal_year' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "سال مالی {$year->title} بازگشایی شد.",
        ]);

        return to_route('fiscal-years.index');
    }

    public function setCurrent(
        Request $request,
        FiscalYear $fiscalYear,
        FiscalYearSetupService $fiscalYears,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageFiscalYears(), 403);

        try {
            $fiscalYear = $fiscalYears->setCurrent(
                $this->mainStore(),
                $fiscalYear,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'fiscal_year' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "سال مالی {$fiscalYear->title} به عنوان سال جاری تعیین شد.",
        ]);

        return to_route('fiscal-years.index');
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
