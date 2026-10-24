<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Store;
use App\Services\Reporting\AuditLogReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __invoke(
        Request $request,
        AuditLogReportService $reports,
    ): Response {
        abort_unless($request->user()?->canViewAuditLogs(), 403);

        $validated = $this->validatedFilters($request, $reports);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $logs = $reports->paginate($store, $validated)
            ->through(fn (AuditLog $log): array => $reports->present($log));

        return Inertia::render('audit/Index', [
            'logs' => $logs,
            'filters' => [
                'search' => $validated['search'] ?? '',
                'event' => $validated['event'] ?? null,
                'entity' => $validated['entity'] ?? null,
                'date' => $validated['date'] ?? null,
            ],
            'model_options' => array_keys($reports->modelMap()),
            'summary' => $reports->summary($store),
        ]);
    }

    public function export(
        Request $request,
        AuditLogReportService $reports,
    ): StreamedResponse {
        abort_unless($request->user()?->canViewAuditLogs(), 403);

        $store = Store::query()->where('code', 'MAIN')->firstOrFail();

        return $reports->export(
            $store,
            $this->validatedFilters($request, $reports),
        );
    }

    /**
     * @return array{search?: string|null, event?: string|null, entity?: string|null, date?: string|null}
     */
    private function validatedFilters(
        Request $request,
        AuditLogReportService $reports,
    ): array {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(['created', 'updated', 'deleted'])],
            'entity' => ['nullable', Rule::in(array_keys($reports->modelMap()))],
            'date' => ['nullable', Rule::in(['today', '7_days'])],
        ]);
    }
}
