<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Reporting\AgingReportService;
use App\Services\Reporting\PartyStatementReportService;
use App\Services\Reporting\SalesOperationsReportService;
use App\Support\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OperationalReportsController extends Controller
{
    public function __invoke(
        Request $request,
        PartyStatementReportService $statements,
        AgingReportService $aging,
        SalesOperationsReportService $sales,
        BranchContextService $branches,
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
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
        ]);
        $from = $validated['from'] ?? $fiscalYear->starts_on->toDateString();
        $to = $validated['to'] ?? $fiscalYear->ends_on->toDateString();

        try {
            $range = ReportDateRange::between($from, $to);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }

        $from = $range->from;
        $to = $range->to;
        $branchId = $branches->active($request->user(), $store)->id;

        $parties = Party::query()
            ->where('store_id', $store->id)
            ->with(['customer:id,party_id', 'supplier:id,party_id'])
            ->orderBy('name')
            ->get(['id', 'store_id', 'code', 'name']);
        $party = $parties->firstWhere(
            'id',
            isset($validated['party_id']) ? (int) $validated['party_id'] : $parties->first()?->id,
        );

        if ($party === null && isset($validated['party_id'])) {
            abort(422, 'طرف‌حساب انتخاب‌شده متعلق به فروشگاه نیست.');
        }

        return Inertia::render('reports/Operations', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'party_id' => $party?->id,
            ],
            'parties' => $parties->map(fn (Party $party): array => [
                'id' => $party->id,
                'code' => $party->code,
                'name' => $party->name,
                'kind' => $party->customer ? 'customer' : 'supplier',
            ]),
            'statement' => $party
                ? $statements->report($party, $store->id, $from, $to, $branchId)
                : null,
            'receivablesAging' => $aging->receivables($store->id, $to, $branchId),
            'payablesAging' => $aging->payables($store->id, $to, $branchId),
            'sales' => $sales->report($store->id, $from, $to, $branchId),
        ]);
    }
}
