<?php

namespace App\Services\Inventory;

use App\Models\InventoryAlert;
use App\Models\InventoryBatchBalance;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InventoryExpiryAlertService
{
    public function __construct(
        private readonly BusinessClock $clock,
    ) {}

    public function refresh(?CarbonImmutable $today = null): int
    {
        $activeBatchIds = [];
        $activeAlerts = [];

        return DB::transaction(function () use ($today, &$activeBatchIds, &$activeAlerts): int {
            $balances = InventoryBatchBalance::query()
                ->with(['batch.product', 'warehouse.branch'])
                ->where('quantity', '>', 0)
                ->whereHas('batch', fn ($query) => $query->whereNotNull('expiry_date'))
                ->get();
            $created = 0;

            foreach ($balances as $balance) {
                $batch = $balance->batch;

                if ($batch === null || $batch->expiry_date === null) {
                    continue;
                }

                $storeToday = $today ?? $this->clock->today($balance->warehouse?->store_id);
                $expiringUntil = $storeToday->addDays(30);
                $type = $batch->expiry_date->lt($storeToday) ? 'expired' : 'expiring';

                if ($type === 'expiring' && $batch->expiry_date->gt($expiringUntil)) {
                    continue;
                }

                $activeBatchIds[] = $batch->id;
                $activeAlerts[$batch->id.'|'.$type.'|'.$balance->branch_id.'|'.$balance->warehouse_id] = true;
                InventoryAlert::query()
                    ->where('product_batch_id', $batch->id)
                    ->where('branch_id', $balance->branch_id)
                    ->where('warehouse_id', $balance->warehouse_id)
                    ->where('type', '!=', $type)
                    ->whereNull('acknowledged_at')
                    ->delete();
                $productName = $batch->product->name;
                $message = $type === 'expired'
                    ? "سری ساخت {$batch->batch_number} از {$productName} منقضی شده است."
                    : "سری ساخت {$batch->batch_number} از {$productName} تا {$batch->expiry_date->toDateString()} منقضی می‌شود.";
                $alert = InventoryAlert::query()->updateOrCreate(
                    [
                        'product_batch_id' => $batch->id,
                        'type' => $type,
                        'branch_id' => $balance->branch_id,
                        'warehouse_id' => $balance->warehouse_id,
                    ],
                    [
                        'store_id' => $balance->store_id,
                        'branch_id' => $balance->branch_id,
                        'warehouse_id' => $balance->warehouse_id,
                        'severity' => $type === 'expired' ? 'critical' : 'warning',
                        'message' => $message,
                    ],
                );

                if ($alert->wasRecentlyCreated) {
                    $created++;
                }
            }

            InventoryAlert::query()
                ->whereNull('acknowledged_at')
                ->get()
                ->each(function (InventoryAlert $alert) use ($activeAlerts): void {
                    $key = $alert->product_batch_id.'|'.$alert->type.'|'.$alert->branch_id.'|'.$alert->warehouse_id;

                    if (! isset($activeAlerts[$key])) {
                        $alert->delete();
                    }
                });

            return $created;
        });
    }
}
