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

        return DB::transaction(function () use ($today, &$activeBatchIds): int {
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
                $productName = $batch->product->name;
                $message = $type === 'expired'
                    ? "سری ساخت {$batch->batch_number} از {$productName} منقضی شده است."
                    : "سری ساخت {$batch->batch_number} از {$productName} تا {$batch->expiry_date->toDateString()} منقضی می‌شود.";
                $alert = InventoryAlert::query()->updateOrCreate(
                    [
                        'product_batch_id' => $batch->id,
                        'type' => $type,
                    ],
                    [
                        'store_id' => $balance->store_id,
                        'branch_id' => $balance->branch_id,
                        'severity' => $type === 'expired' ? 'critical' : 'warning',
                        'message' => $message,
                    ],
                );

                if ($alert->wasRecentlyCreated) {
                    $created++;
                }
            }

            InventoryAlert::query()
                ->when(
                    $activeBatchIds !== [],
                    fn ($query) => $query->whereNotIn('product_batch_id', $activeBatchIds),
                    fn ($query) => $query,
                )
                ->delete();

            return $created;
        });
    }
}
