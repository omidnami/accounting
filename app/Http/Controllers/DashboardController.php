<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Dashboard\DashboardService;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): Response
    {
        $store = Store::query()->where('code', 'MAIN')->first();
        $branch = null;

        if ($store !== null) {
            try {
                $branch = app(BranchContextService::class)
                    ->active($request->user(), $store);
            } catch (DomainException) {
                $branch = null;
            }
        }
        $fiscalYear = $store?->fiscalYears()->where('is_current', true)->first();

        if (! $store || ! $branch || ! $fiscalYear) {
            return Inertia::render('DashboardHome', [
                'dashboard' => [
                    'mode' => 'setup',
                    'store' => null,
                    'branch' => null,
                    'fiscal_year' => null,
                    'metrics' => [],
                    'trend' => [],
                    'monthly_trend' => [],
                    'low_stock' => ['count' => 0, 'items' => []],
                    'recent_activity' => [],
                    'setup_required' => true,
                ],
            ]);
        }

        return Inertia::render('DashboardHome', [
            'dashboard' => $dashboard->overview(
                $request->user(),
                $store,
                $branch,
                $fiscalYear,
            ),
        ]);
    }
}
