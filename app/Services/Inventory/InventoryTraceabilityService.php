<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class InventoryTraceabilityService
{
    /**
     * @return Collection<int, list<
     *     array{
     *         batch_id: int<0, max>, batch_number: string,
     *         expiry_date: string|null, quantity: numeric-string,
     *         serials: array<int, non-empty-string>
     *     }|array{
     *         batch_id: null, batch_number: null, expiry_date: null,
     *         quantity: numeric-string, serials: array<int, non-empty-string>
     *     }
     * >>
     */
    public function forSource(Model $source): Collection
    {
        return InventoryMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->with([
                'batchAssignments.batch:id,batch_number,expiry_date',
                'serialAssignments.serial:id,product_id,serial_number,product_batch_id',
            ])
            ->get()
            ->groupBy(fn (InventoryMovement $movement): int => (int) $movement->product_id)
            ->map(function ($movements): array {
                $rows = [];

                foreach ($movements as $movement) {
                    $serialByBatch = $movement->serialAssignments
                        ->groupBy(fn ($assignment): int => (int) $assignment->serial->product_batch_id);

                    if ($movement->batchAssignments->isEmpty()) {
                        $rows[] = [
                            'batch_id' => null,
                            'batch_number' => null,
                            'expiry_date' => null,
                            'quantity' => Decimal::normalize($movement->quantity, 3),
                            'serials' => $movement->serialAssignments
                                ->map(fn ($assignment): string => (string) $assignment->serial->serial_number)
                                ->filter(fn (string $serial): bool => $serial !== '')
                                ->values()
                                ->all(),
                        ];

                        continue;
                    }

                    foreach ($movement->batchAssignments as $assignment) {
                        $rows[] = [
                            'batch_id' => (int) $assignment->batch->id,
                            'batch_number' => (string) $assignment->batch->batch_number,
                            'expiry_date' => $assignment->batch->expiry_date?->toDateString(),
                            'quantity' => Decimal::normalize($assignment->quantity, 3),
                            'serials' => ($serialByBatch->get($assignment->product_batch_id) ?? collect())
                                ->map(fn ($serialAssignment): string => (string) $serialAssignment->serial->serial_number)
                                ->filter(fn (string $serial): bool => $serial !== '')
                                ->values()
                                ->all(),
                        ];
                    }
                }

                return $rows;
            })
            ->values();
    }
}
