<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Reporting\PersonalActivityReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MyActivityController extends Controller
{
    public function __invoke(Request $request, PersonalActivityReportService $activities): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', Rule::in(['today', '7_days'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:2'],
        ]);

        return Inertia::render('settings/Activity', [
            'activities' => $activities->paginate($request->user(), $validated['date'] ?? null),
            'filters' => ['date' => $validated['date'] ?? null],
        ]);
    }
}
