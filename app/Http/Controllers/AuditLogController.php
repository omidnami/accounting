<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->canViewAuditLogs(), 403);

        $modelMap = $this->modelMap();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(['created', 'updated', 'deleted'])],
            'entity' => ['nullable', Rule::in(array_keys($modelMap))],
            'date' => ['nullable', Rule::in(['today', '7_days'])],
        ]);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $event = $validated['event'] ?? null;
        $entity = $validated['entity'] ?? null;
        $date = $validated['date'] ?? null;
        $base = AuditLog::query()
            ->where(fn (Builder $query) => $query
                ->where('store_id', $store->id)
                ->orWhereNull('store_id'));
        $logs = (clone $base)
            ->with('actor:id,name,email')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn (Builder $query) => $query
                    ->where('description', 'like', $term)
                    ->orWhere('auditable_type', 'like', $term)
                    ->orWhere('auditable_id', 'like', $term)
                    ->orWhereHas('actor', fn (Builder $query) => $query
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)));
            })
            ->when($event !== null, fn (Builder $query) => $query->where('event', $event))
            ->when($entity !== null, fn (Builder $query) => $query
                ->where('auditable_type', $modelMap[$entity]))
            ->when($date === 'today', fn (Builder $query) => $query
                ->whereDate('created_at', today()))
            ->when($date === '7_days', fn (Builder $query) => $query
                ->where('created_at', '>=', now()->subDays(7)))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'entity' => class_basename($log->auditable_type),
                'entity_id' => $log->auditable_id,
                'description' => $log->description,
                'actor' => $log->actor
                    ? [
                        'name' => $log->actor->name,
                        'email' => $log->actor->email,
                    ]
                    : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toDateTimeString(),
            ]);

        return Inertia::render('audit/Index', [
            'logs' => $logs,
            'filters' => [
                'search' => $search,
                'event' => $event,
                'entity' => $entity,
                'date' => $date,
            ],
            'model_options' => array_keys($modelMap),
            'summary' => [
                'total' => (clone $base)->count(),
                'today' => (clone $base)->whereDate('created_at', today())->count(),
                'security' => (clone $base)->where('auditable_type', User::class)->count(),
                'financial' => (clone $base)->whereIn('auditable_type', [
                    Invoice::class,
                    Receipt::class,
                    Payment::class,
                    JournalEntry::class,
                ])->count(),
            ],
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    private function modelMap(): array
    {
        $map = [];

        foreach (config('audit.models', []) as $model) {
            $map[class_basename($model)] = $model;
        }

        ksort($map);

        return $map;
    }
}
