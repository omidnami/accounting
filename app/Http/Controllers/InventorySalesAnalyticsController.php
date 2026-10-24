<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Reporting\InventorySalesAnalyticsService;
use App\Support\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventorySalesAnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        InventorySalesAnalyticsService $analytics,
    ): Response {
        abort_unless($request->user()?->canViewFinancialReports(), 403);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->firstOrFail();
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $validated['from'] ?? $fiscalYear->starts_on->toDateString();
        $to = $validated['to'] ?? $fiscalYear->ends_on->toDateString();

        try {
            $range = ReportDateRange::between($from, $to);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }

        return Inertia::render('reports/Analytics', [
            ...$analytics->report($store->id, $branch->id, $range->from, $range->to),
        ]);
    }
}
