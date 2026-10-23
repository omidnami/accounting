<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): Response
    {
        $store = Store::query()->where('code', 'MAIN')->first();
        $branch = $store?->defaultBranch()->where('is_active', true)->first();
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
