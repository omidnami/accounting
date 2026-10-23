<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class AuditLogService
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $event,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        ?string $description = null,
    ): ?AuditLog {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $request = app()->runningInConsole() ? null : request();
        $actor = Auth::user();

        return AuditLog::query()->create([
            'store_id' => $model->getAttribute('store_id'),
            'branch_id' => $model->getAttribute('branch_id'),
            'user_id' => $actor?->getAuthIdentifier(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'description' => $description ?? class_basename($model)." {$event}",
            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
