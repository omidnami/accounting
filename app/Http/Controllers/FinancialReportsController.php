<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Reporting\FinancialStatementReportService;
use App\Services\Reporting\JournalReportService;
use App\Services\Reporting\TrialBalanceReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialReportsController extends Controller
{
    public function __invoke(
        Request $request,
        JournalReportService $journals,
        TrialBalanceReportService $trialBalance,
        FinancialStatementReportService $statements,
    ): Response {
        abort_unless($request->user()?->canViewFinancialReports(), 403);

        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->firstOrFail();
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = $validated['from'] ?? $fiscalYear->starts_on->toDateString();
        $to = $validated['to'] ?? $fiscalYear->ends_on->toDateString();
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        if ($branchId !== null && ! Branch::query()
            ->whereKey($branchId)
            ->where('store_id', $store->id)
            ->exists()) {
            abort(422, 'شعبه انتخاب‌شده متعلق به فروشگاه نیست.');
        }

        return Inertia::render('reports/Index', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'branch_id' => $branchId,
            ],
            'branches' => $store->branches()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'name']),
            'trialBalance' => $trialBalance->report($store->id, $from, $to, $branchId),
            'incomeStatement' => $statements->incomeStatement($store->id, $from, $to, $branchId),
            'balanceSheet' => $statements->balanceSheet($store->id, $to, $branchId),
            'journal' => $journals->paginate(
                $store->id,
                $from,
                $to,
                $branchId,
                15,
                (int) ($validated['page'] ?? 1),
            ),
        ]);
    }
}
