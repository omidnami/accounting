<?php

namespace App\Services\Reporting;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogReportService
{
    public function __construct(
        private readonly CsvExportService $csv,
    ) {}

    /**
     * @return array<string, class-string>
     */
    public function modelMap(): array
    {
        $map = [];

        foreach (config('audit.models', []) as $model) {
            $map[class_basename($model)] = $model;
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  array{search?: string|null, event?: string|null, entity?: string|null, date?: string|null}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(Store $store, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($store, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{total: int, today: int, security: int, financial: int}
     */
    public function summary(Store $store): array
    {
        $base = $this->baseQuery($store);

        return [
            'total' => (clone $base)->count(),
            'today' => (clone $base)->whereDate('created_at', today())->count(),
            'security' => (clone $base)->where('auditable_type', User::class)->count(),
            'financial' => (clone $base)->whereIn('auditable_type', [
                Invoice::class,
                Receipt::class,
                Payment::class,
                JournalEntry::class,
            ])->count(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     event: string,
     *     entity: string,
     *     entity_id: int,
     *     description: string,
     *     actor: array{name: string, email: string|null}|null,
     *     old_values: array<string, mixed>|null,
     *     new_values: array<string, mixed>|null,
     *     ip_address: string|null,
     *     created_at: string
     * }
     */
    public function present(AuditLog $log): array
    {
        $actor = $log->actor;

        return [
            'id' => $log->id,
            'event' => $log->event,
            'entity' => class_basename($log->auditable_type),
            'entity_id' => $log->auditable_id,
            'description' => $log->description,
            'actor' => $actor !== null || $log->actor_name
                ? [
                    'name' => $actor !== null
                        ? $actor->name
                        : $log->actor_name,
                    'email' => $actor !== null
                        ? $actor->email
                        : $log->actor_email,
                ]
                : null,
            'old_values' => $this->values($log->old_values),
            'new_values' => $this->values($log->new_values),
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at->toDateTimeString(),
        ];
    }

    /**
     * @param  array{search?: string|null, event?: string|null, entity?: string|null, date?: string|null}  $filters
     */
    public function export(Store $store, array $filters): StreamedResponse
    {
        $rows = $this->query($store, $filters)
            ->orderByDesc('id')
            ->cursor()
            ->map(function (AuditLog $log): array {
                $actor = $log->actor;

                return [
                    $log->created_at->toDateTimeString(),
                    $log->event,
                    class_basename($log->auditable_type),
                    $log->auditable_id,
                    $log->description,
                    $actor !== null ? $actor->name : $log->actor_name,
                    $actor !== null ? $actor->email : $log->actor_email,
                    $log->ip_address,
                    $this->json($this->values($log->old_values)),
                    $this->json($this->values($log->new_values)),
                ];
            });

        return $this->csv->download(
            'audit-logs-'.now()->format('Y-m-d-His').'.csv',
            [
                'زمان', 'رویداد', 'موجودیت', 'شناسه موجودیت', 'شرح',
                'کاربر', 'ایمیل کاربر', 'IP', 'مقادیر قبل', 'مقادیر بعد',
            ],
            $rows,
        );
    }

    /**
     * @param  array{search?: string|null, event?: string|null, entity?: string|null, date?: string|null}  $filters
     * @return Builder<AuditLog>
     */
    private function query(Store $store, array $filters): Builder
    {
        $search = trim(Digits::toLatin((string) ($filters['search'] ?? '')));
        $event = $filters['event'] ?? null;
        $entity = $filters['entity'] ?? null;
        $date = $filters['date'] ?? null;
        $modelMap = $this->modelMap();

        return $this->baseQuery($store)
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
                ->where('created_at', '>=', now()->subDays(7)));
    }

    /**
     * @return Builder<AuditLog>
     */
    private function baseQuery(Store $store): Builder
    {
        return AuditLog::query()
            ->where(fn (Builder $query) => $query
                ->where('store_id', $store->id)
                ->orWhereNull('store_id'));
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    private function json(?array $values): string
    {
        if ($values === null) {
            return '';
        }

        return json_encode(
            $values,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function values(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
