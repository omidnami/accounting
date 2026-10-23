<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function created(Model $model): void
    {
        $newValues = $this->values($model->getAttributes(), $model);

        $this->auditLogs->record('created', $model, [], $newValues);
    }

    public function updated(Model $model): void
    {
        $oldValues = [];
        $newValues = [];

        foreach (array_keys($model->getChanges()) as $attribute) {
            if ($this->excluded($attribute)) {
                continue;
            }

            $oldValues[$attribute] = $this->normalizeValue(
                $model->getOriginal($attribute),
            );
            $newValues[$attribute] = $this->normalizeValue(
                $model->getAttribute($attribute),
            );
        }

        if ($model instanceof User && $model->wasChanged('password')) {
            $oldValues['password'] = '[redacted]';
            $newValues['password'] = '[redacted]';
        }

        if ($oldValues === [] && $newValues === []) {
            return;
        }

        $this->auditLogs->record('updated', $model, $oldValues, $newValues);
    }

    public function deleted(Model $model): void
    {
        $oldValues = $this->values($model->getOriginal(), $model);

        $this->auditLogs->record('deleted', $model, $oldValues, []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes, Model $model): array
    {
        $values = [];

        foreach ($attributes as $attribute => $value) {
            if ($this->excluded($attribute)) {
                continue;
            }

            $values[$attribute] = $this->normalizeValue($value);
        }

        if ($model instanceof User && array_key_exists('password', $attributes)) {
            $values['password'] = '[redacted]';
        }

        return $values;
    }

    private function excluded(string $attribute): bool
    {
        return in_array(
            $attribute,
            config('audit.excluded_attributes', []),
            true,
        );
    }

    private function normalizeValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
