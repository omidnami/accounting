<?php

namespace App\Console\Commands;

use App\Enums\InvoiceType;
use App\Enums\PartyEntityType;
use App\Models\Invoice;
use App\Services\Audit\AuditLogService;
use App\Services\Parties\PartySnapshotService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

class BackfillSalesInvoicePartySnapshots extends Command
{
    protected $signature = 'sales:backfill-party-snapshots
        {--dry-run : Report candidates without changing data (default)}
        {--apply : Apply evidence-backed snapshots and write separate audit records}';

    protected $description = 'Safely backfill legacy sales invoice party snapshots from complete Party audit evidence';

    private const int CHUNK_SIZE = 200;

    private const array PARTY_FIELDS = [
        'id',
        'store_id',
        'code',
        'entity_type',
        'name',
        'phone',
        'email',
        'national_id',
        'tax_id',
        'address',
        'version',
    ];

    private const array SNAPSHOT_FIELDS = [
        'schema_version',
        'party_id',
        'party_version',
        'store_id',
        'code',
        'entity_type',
        'name',
        'phone',
        'email',
        'national_id',
        'tax_id',
        'address',
    ];

    public function __construct(private readonly AuditLogService $auditLogs)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->error('--dry-run and --apply cannot be used together.');

            return self::FAILURE;
        }

        foreach (['invoices', 'customers', 'parties', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Required table [{$table}] is missing.");

                return self::FAILURE;
            }
        }

        $counts = array_fill_keys([
            'would_backfill',
            'backfilled',
            'no_evidence',
            'invalid_snapshot',
            'snapshot_mismatch',
            'unchanged',
            'skipped_concurrent',
        ], 0);

        $this->info($apply
            ? 'Applying evidence-backed sales invoice snapshots.'
            : 'Dry run: no invoices or audit records will be changed.');

        Invoice::query()
            ->where('type', InvoiceType::Sales)
            ->with(['customer:id,party_id', 'customer.party'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $invoices) use ($apply, &$counts): void {
                foreach ($invoices as $invoice) {
                    $inspection = $this->inspect($invoice);
                    $status = $inspection['status'];

                    if ($apply && $status === 'eligible') {
                        $snapshot = $inspection['snapshot'] ?? null;
                        $auditIds = $inspection['audit_ids'] ?? [];

                        if (! is_array($snapshot)) {
                            throw new RuntimeException('Eligible invoice is missing its reconstructed snapshot.');
                        }

                        $status = $this->applySnapshot($invoice, $snapshot, $auditIds)
                            ? 'backfilled'
                            : 'skipped_concurrent';
                    } elseif (! $apply && $status === 'eligible') {
                        $status = 'would_backfill';
                    }

                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                    $this->line(sprintf(
                        '[%s] %s: %s',
                        $status,
                        $invoice->invoice_number,
                        $inspection['detail'],
                    ));
                }
            });

        ksort($counts);

        foreach ($counts as $status => $count) {
            $this->info("{$status}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{status: string, detail: string, snapshot?: array<string, mixed>, audit_ids?: array<int, int>}
     */
    private function inspect(Invoice $invoice): array
    {
        if ($invoice->getRawOriginal('party_snapshot') !== null) {
            return $this->inspectExistingSnapshot($invoice);
        }

        $reconstructed = $this->reconstruct($invoice);

        if ($reconstructed['snapshot'] === null) {
            return [
                'status' => 'no_evidence',
                'detail' => $reconstructed['reason'] ?? 'complete Party audit evidence is unavailable',
            ];
        }

        return [
            'status' => 'eligible',
            'detail' => 'evidence audit ids: '.implode(', ', $reconstructed['audit_ids']),
            'snapshot' => $reconstructed['snapshot'],
            'audit_ids' => $reconstructed['audit_ids'],
        ];
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function inspectExistingSnapshot(Invoice $invoice): array
    {
        $decoded = $this->decodeSnapshot($invoice->getRawOriginal('party_snapshot'));
        $snapshot = $decoded['snapshot'];

        if ($snapshot === null) {
            return [
                'status' => 'invalid_snapshot',
                'detail' => $decoded['error'] ?? 'snapshot is not a JSON object',
            ];
        }

        $validationError = $this->snapshotValidationError($snapshot);

        if ($validationError !== null) {
            return [
                'status' => 'invalid_snapshot',
                'detail' => $validationError,
            ];
        }

        $reconstructed = $this->reconstruct($invoice);

        if ($reconstructed['snapshot'] === null) {
            return [
                'status' => 'unchanged',
                'detail' => 'existing snapshot kept; '.($reconstructed['reason'] ?? 'audit evidence is unavailable'),
            ];
        }

        $differences = $this->snapshotDifferences($snapshot, $reconstructed['snapshot']);

        if ($differences !== []) {
            return [
                'status' => 'snapshot_mismatch',
                'detail' => 'existing snapshot kept; different fields: '.implode(', ', $differences),
            ];
        }

        return [
            'status' => 'unchanged',
            'detail' => 'existing snapshot matches the audit evidence',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, int>  $auditIds
     */
    private function applySnapshot(Invoice $invoice, array $snapshot, array $auditIds): bool
    {
        return DB::transaction(function () use ($invoice, $snapshot, $auditIds): bool {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->getRawOriginal('party_snapshot') !== null) {
                return false;
            }

            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $updated = DB::table('invoices')
                ->where('id', $locked->getKey())
                ->whereNull('party_snapshot')
                ->update(['party_snapshot' => $encoded]);

            if ($updated === 0) {
                return false;
            }

            $audit = $this->auditLogs->record(
                'sales_snapshot_backfilled',
                $locked,
                ['party_snapshot' => null],
                [
                    'party_snapshot' => $snapshot,
                    'evidence_audit_ids' => $auditIds,
                    'invoice_created_at' => $locked->getRawOriginal('created_at'),
                    'time_policy' => 'invoices.created_at',
                ],
                'بازسازی snapshot طرف‌حساب فاکتور فروش قدیمی از AuditLog',
            );

            if ($audit === null) {
                throw new RuntimeException('The backfill audit record could not be created.');
            }

            return true;
        });
    }

    /**
     * @return array{snapshot: array<string, mixed>|null, audit_ids: array<int, int>, reason: string|null}
     */
    private function reconstruct(Invoice $invoice): array
    {
        /** @var array{snapshot: array<string, mixed>|null, audit_ids: array<int, int>, reason: string|null} $result */
        $result = [
            'snapshot' => null,
            'audit_ids' => [],
            'reason' => null,
        ];
        $party = $invoice->customer?->party;

        if ($party === null) {
            $result['reason'] = 'the invoice customer Party is unavailable';

            return $result;
        }

        $cutoff = $invoice->getRawOriginal('created_at');

        if (! is_string($cutoff) || $cutoff === '') {
            $result['reason'] = 'invoices.created_at is unavailable';

            return $result;
        }

        $logs = DB::table('audit_logs')
            ->where('auditable_type', $party->getMorphClass())
            ->where('auditable_id', $party->getKey())
            ->where('store_id', $invoice->store_id)
            ->whereIn('event', ['created', 'updated'])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'event', 'old_values', 'new_values']);

        if ($logs->isEmpty()) {
            $result['reason'] = 'no Party audit events exist at or before invoices.created_at';

            return $result;
        }

        /** @var array<string, mixed>|null $state */
        $state = null;
        $auditIds = [];

        foreach ($logs as $log) {
            $auditId = (int) $log->id;
            $auditIds[] = $auditId;
            $newValues = $this->decodeValues($log->new_values);

            if ($newValues === null) {
                $result['audit_ids'] = $auditIds;
                $result['reason'] = "audit {$auditId} has invalid new_values";

                return $result;
            }

            if ($log->event === 'created') {
                if ($state !== null) {
                    $result['audit_ids'] = $auditIds;
                    $result['reason'] = "multiple Party created events were found at audit {$auditId}";

                    return $result;
                }

                $state = $newValues;

                continue;
            }

            if ($state === null) {
                $result['audit_ids'] = $auditIds;
                $result['reason'] = "updated audit {$auditId} appears before a complete created event";

                return $result;
            }

            $oldValues = $this->decodeValues($log->old_values);

            if ($oldValues === null) {
                $result['audit_ids'] = $auditIds;
                $result['reason'] = "updated audit {$auditId} has invalid old_values";

                return $result;
            }

            foreach (self::PARTY_FIELDS as $field) {
                if (! array_key_exists($field, $state) && array_key_exists($field, $oldValues)) {
                    $state[$field] = $oldValues[$field];
                }
            }

            foreach (self::PARTY_FIELDS as $field) {
                if (! array_key_exists($field, $newValues)) {
                    continue;
                }

                if (! array_key_exists($field, $oldValues)
                    || ! $this->auditValuesMatch($field, $oldValues[$field], $state[$field])) {
                    $result['audit_ids'] = $auditIds;
                    $result['reason'] = "updated audit {$auditId} has a discontinuous {$field} value";

                    return $result;
                }

                $state[$field] = $newValues[$field];
            }
        }

        if ($state === null) {
            $result['reason'] = 'no complete Party created event was found';

            return $result;
        }

        $snapshot = $this->snapshotFromPartyState($state, $invoice);

        if ($snapshot === null) {
            $result['audit_ids'] = $auditIds;
            $result['reason'] = 'Party audit evidence does not form a complete valid identity';

            return $result;
        }

        $result['snapshot'] = $snapshot;
        $result['audit_ids'] = $auditIds;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function snapshotFromPartyState(array $state, Invoice $invoice): ?array
    {
        $currentParty = $invoice->customer?->party;

        if ($currentParty === null) {
            return null;
        }

        foreach (['id', 'store_id', 'code'] as $field) {
            if (! array_key_exists($field, $state)) {
                $state[$field] = $currentParty->getAttribute($field);
            }
        }

        if (! array_key_exists('entity_type', $state)) {
            $entityType = $currentParty->getAttribute('entity_type');
            $state['entity_type'] = $entityType instanceof PartyEntityType
                ? $entityType->value
                : PartyEntityType::Individual->value;
        }

        if (! array_key_exists('version', $state)) {
            $state['version'] = 0;
        }

        foreach (['phone', 'email', 'national_id', 'tax_id', 'address'] as $field) {
            if (array_key_exists($field, $state)) {
                continue;
            }

            if ($currentParty->getAttribute($field) !== null) {
                return null;
            }

            $state[$field] = null;
        }

        foreach (['id', 'store_id', 'version'] as $field) {
            $value = $state[$field] ?? null;

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return null;
            }
        }

        foreach (['code', 'entity_type', 'name'] as $field) {
            $value = $state[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return null;
            }
        }

        $entityType = $state['entity_type'];

        if (! is_string($entityType) || PartyEntityType::tryFrom($entityType) === null) {
            return null;
        }

        foreach (['phone', 'email', 'national_id', 'tax_id', 'address'] as $field) {
            if (! array_key_exists($field, $state)
                || (! is_string($state[$field]) && $state[$field] !== null)) {
                return null;
            }
        }

        $snapshot = [
            'schema_version' => PartySnapshotService::SCHEMA_VERSION,
            'party_id' => (int) $state['id'],
            'party_version' => (int) $state['version'],
            'store_id' => (int) $state['store_id'],
            'code' => $state['code'],
            'entity_type' => $state['entity_type'],
            'name' => $state['name'],
            'phone' => $state['phone'],
            'email' => $state['email'],
            'national_id' => $state['national_id'],
            'tax_id' => $state['tax_id'],
            'address' => $state['address'],
        ];

        if ($snapshot['party_id'] !== (int) $currentParty->id
            || $snapshot['store_id'] !== (int) $invoice->store_id) {
            return null;
        }

        return $snapshot;
    }

    /**
     * @return array{snapshot: array<string, mixed>|null, error: string|null}
     */
    private function decodeSnapshot(mixed $raw): array
    {
        if (is_array($raw)) {
            return ['snapshot' => $raw, 'error' => null];
        }

        if (! is_string($raw)) {
            return ['snapshot' => null, 'error' => 'snapshot has an unsupported database value'];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['snapshot' => null, 'error' => 'snapshot contains malformed JSON'];
        }

        return is_array($decoded)
            ? ['snapshot' => $decoded, 'error' => null]
            : ['snapshot' => null, 'error' => 'snapshot is not a JSON object'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeValues(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotValidationError(array $snapshot): ?string
    {
        $actualKeys = array_keys($snapshot);
        $expectedKeys = self::SNAPSHOT_FIELDS;
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            return 'snapshot schema fields are invalid';
        }

        if ($snapshot['schema_version'] !== PartySnapshotService::SCHEMA_VERSION) {
            return 'snapshot schema_version is unsupported';
        }

        foreach (['party_id', 'store_id'] as $field) {
            if (! is_int($snapshot[$field]) || $snapshot[$field] <= 0) {
                return "snapshot {$field} is invalid";
            }
        }

        if (! is_int($snapshot['party_version']) || $snapshot['party_version'] < 0) {
            return 'snapshot party_version is invalid';
        }

        foreach (['code', 'name'] as $field) {
            if (! is_string($snapshot[$field]) || trim($snapshot[$field]) === '') {
                return "snapshot {$field} is invalid";
            }
        }

        if (! is_string($snapshot['entity_type'])
            || PartyEntityType::tryFrom($snapshot['entity_type']) === null) {
            return 'snapshot entity_type is invalid';
        }

        foreach (['phone', 'email', 'national_id', 'tax_id', 'address'] as $field) {
            if (! is_string($snapshot[$field]) && $snapshot[$field] !== null) {
                return "snapshot {$field} is invalid";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     * @return array<int, string>
     */
    private function snapshotDifferences(array $actual, array $expected): array
    {
        $differences = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            if ($actual[$field] !== $expected[$field]) {
                $differences[] = $field;
            }
        }

        return $differences;
    }

    private function auditValuesMatch(string $field, mixed $oldValue, mixed $stateValue): bool
    {
        if (in_array($field, ['id', 'store_id', 'version'], true)) {
            return (is_int($oldValue) || (is_string($oldValue) && ctype_digit($oldValue)))
                && (is_int($stateValue) || (is_string($stateValue) && ctype_digit($stateValue)))
                && (int) $oldValue === (int) $stateValue;
        }

        return $oldValue === $stateValue;
    }
}
