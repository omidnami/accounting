<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFiscalYearRequest;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Accounting\FiscalYearSetupService;
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
        abort_unless($request->user()?->canManageFiscalYears(), 403);

        return Inertia::render(
            'fiscal-years/Index',
            $fiscalYears->overviewForStore($this->mainStore()),
        );
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
