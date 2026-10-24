<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\JournalEntryKind;
use App\Enums\StockCountStatus;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockCountService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AccountingPeriodLockService $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(Store $store, array $data, User $creator): StockCount
    {
        $requestHash = $this->requestHash($data);

        try {
            return DB::transaction(function () use ($store, $data, $creator, $requestHash): StockCount {
                $existing = StockCount::query()
                    ->where('idempotency_key', (string) $data['submission_token'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->assertIdempotentMatch($existing, $requestHash);

                    return $existing->load(['warehouse', 'items.product']);
                }

                $warehouse = $this->warehouse($store, (int) $data['warehouse_id']);
                $context = $this->periods->lockCurrentOpenPeriod(
                    $store->id,
                    $warehouse->branch_id,
                    (string) $data['count_date'],
                );
                $items = $this->normalizedItems(
                    $data['items'],
                    $creator->canViewInventoryCosts(),
                );
                $count = StockCount::query()->create([
                    'store_id' => $store->id,
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'fiscal_year_id' => $context['fiscal_year']->id,
                    'accounting_period_id' => $context['period']->id,
                    'count_number' => 'STK-'.strtoupper((string) Str::ulid()),
                    'count_date' => (string) $data['count_date'],
                    'notes' => $data['notes'] ?? null,
                    'idempotency_key' => (string) $data['submission_token'],
                    'idempotency_request_hash' => $requestHash,
                    'created_by' => $creator->id,
                ]);
                $this->storeItems($count, $warehouse, $items);

                return $count->refresh()->load(['warehouse', 'items.product']);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = StockCount::query()
                ->where('idempotency_key', (string) $data['submission_token'])
                ->first();

            if ($existing) {
                $this->assertIdempotentMatch($existing, $requestHash);

                return $existing->load(['warehouse', 'items.product']);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(
        Store $store,
        StockCount $count,
        array $data,
        User $actor,
    ): StockCount {
        return DB::transaction(function () use ($store, $count, $data, $actor): StockCount {
            $locked = StockCount::query()
                ->whereKey($count->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== StockCountStatus::Draft) {
                throw new DomainException('فقط شمارش پیش‌نویس قابل ویرایش است.');
            }

            if (! $actor->canViewInventoryCosts()
                && (int) $locked->created_by !== (int) $actor->id) {
                throw new DomainException('انباردار فقط می‌تواند شمارش ایجادشده توسط خودش را ویرایش کند.');
            }

            $warehouse = $this->warehouse($store, (int) $data['warehouse_id']);
            $context = $this->periods->lockCurrentOpenPeriod(
                $store->id,
                $warehouse->branch_id,
                (string) $data['count_date'],
            );
            $items = $this->normalizedItems(
                $data['items'],
                $actor->canViewInventoryCosts(),
            );
            $locked->forceFill([
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'fiscal_year_id' => $context['fiscal_year']->id,
                'accounting_period_id' => $context['period']->id,
                'count_date' => (string) $data['count_date'],
                'notes' => $data['notes'] ?? null,
            ])->save();
            $locked->items()->delete();
            $this->storeItems($locked, $warehouse, $items);

            return $locked->refresh()->load(['warehouse', 'items.product']);
        }, attempts: 3);
    }

    public function postCount(
        Store $store,
        StockCount $count,
        User $approver,
    ): StockCount {
        if (! $approver->canPostStockCounts()) {
            throw new DomainException('ثبت شمارش و تعدیل موجودی فقط برای مدیر و حسابدار مجاز است.');
        }

        return DB::transaction(function () use ($store, $count, $approver): StockCount {
            $locked = StockCount::query()
                ->whereKey($count->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException('شمارش متعلق به این فروشگاه نیست.');
            }

            if ($locked->status === StockCountStatus::Posted) {
                return $locked->load(['items.product']);
            }

            if ($locked->status !== StockCountStatus::Draft) {
                throw new DomainException('شمارش انتخاب‌شده قابل ثبت نیست.');
            }

            $warehouse = $this->warehouse($store, $locked->warehouse_id);
            $context = $this->periods->lockOpenPeriod(
                $store->id,
                $warehouse->branch_id,
                $locked->fiscal_year_id,
                $locked->count_date->toDateString(),
            );

            if ((int) $context['period']->id !== (int) $locked->accounting_period_id) {
                throw new DomainException('دوره شمارش با تاریخ آن سازگار نیست.');
            }

            $items = $locked->items()
                ->with('product')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new DomainException('شمارش باید حداقل یک کالا داشته باشد.');
            }

            $inboundValue = '0.00';
            $outboundValue = '0.00';

            foreach ($items as $item) {
                $balance = InventoryBalance::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();
                $systemQuantity = Decimal::normalize(
                    $balance === null ? 0 : $balance->quantity,
                    Decimal::QUANTITY_SCALE,
                );
                $difference = Decimal::subtract(
                    $item->counted_quantity,
                    $systemQuantity,
                    Decimal::QUANTITY_SCALE,
                );
                $item->forceFill([
                    'system_quantity' => $systemQuantity,
                    'difference_quantity' => $difference,
                ])->save();

                if (Decimal::isZero($difference)) {
                    continue;
                }

                if ($item->product->track_serials) {
                    throw new DomainException(
                        'تعدیل شمارش برای کالای سریال‌دار فعلاً مجاز نیست؛ '
                        .'ابتدا سریال‌های فیزیکی را وارد یا از مسیر ابطال سریال اصلاح کنید.',
                    );
                }

                if (Decimal::compare($difference, '0', Decimal::QUANTITY_SCALE) > 0) {
                    $unitCost = $this->surplusUnitCost($item, $balance, $item->product);
                    $movement = $this->inventory->receive(
                        warehouseId: $warehouse->id,
                        productId: $item->product_id,
                        quantity: $difference,
                        unitCost: $unitCost,
                        movementDate: $locked->count_date->toDateString(),
                        idempotencyKey: "stock-count:{$locked->id}:product:{$item->product_id}:in",
                        movementType: InventoryMovementType::Adjustment,
                        source: $locked,
                        createdBy: $approver->id,
                        reference: $locked->count_number,
                        description: 'افزایش موجودی از شمارش فیزیکی',
                    );
                    $inboundValue = Decimal::add($inboundValue, $movement->total_cost, 2);
                    $item->forceFill(['unit_cost' => $movement->unit_cost])->save();
                } else {
                    $movement = $this->inventory->issue(
                        warehouseId: $warehouse->id,
                        productId: $item->product_id,
                        quantity: ltrim($difference, '-'),
                        movementDate: $locked->count_date->toDateString(),
                        idempotencyKey: "stock-count:{$locked->id}:product:{$item->product_id}:out",
                        movementType: InventoryMovementType::Adjustment,
                        source: $locked,
                        createdBy: $approver->id,
                        reference: $locked->count_number,
                        description: 'کاهش موجودی از شمارش فیزیکی',
                    );
                    $outboundValue = Decimal::add($outboundValue, $movement->total_cost, 2);
                    $item->forceFill(['unit_cost' => $movement->unit_cost])->save();
                }
            }

            if (! Decimal::isZero($inboundValue) || ! Decimal::isZero($outboundValue)) {
                $lines = [];
                $inventoryAccountId = $this->settings->defaultAccountId('inventory', $store->id);

                if (! Decimal::isZero($inboundValue)) {
                    $lines[] = [
                        'account_id' => $inventoryAccountId,
                        'debit' => $inboundValue,
                        'credit' => '0.00',
                        'description' => 'افزایش موجودی از شمارش',
                    ];
                    $lines[] = [
                        'account_id' => $this->settings->defaultAccountId('inventory_adjustment_gain', $store->id),
                        'debit' => '0.00',
                        'credit' => $inboundValue,
                        'description' => 'درآمد تعدیل موجودی',
                    ];
                }

                if (! Decimal::isZero($outboundValue)) {
                    $lines[] = [
                        'account_id' => $this->settings->defaultAccountId('inventory_adjustment_loss', $store->id),
                        'debit' => $outboundValue,
                        'credit' => '0.00',
                        'description' => 'کسری موجودی از شمارش',
                    ];
                    $lines[] = [
                        'account_id' => $inventoryAccountId,
                        'debit' => '0.00',
                        'credit' => $outboundValue,
                        'description' => 'کاهش موجودی از شمارش',
                    ];
                }

                $this->posting->post(
                    [
                        'store_id' => $store->id,
                        'branch_id' => $warehouse->branch_id,
                        'fiscal_year_id' => $locked->fiscal_year_id,
                        'accounting_period_id' => $locked->accounting_period_id,
                        'entry_kind' => JournalEntryKind::System,
                        'entry_date' => $locked->count_date->toDateString(),
                        'description' => "تعدیل موجودی شمارش {$locked->count_number}",
                        'source_type' => $locked->getMorphClass(),
                        'source_id' => $locked->id,
                        'created_by' => $approver->id,
                    ],
                    $lines,
                    $approver,
                );
            }

            $locked->markPosted($approver);

            return $locked->refresh()->load(['warehouse', 'items.product']);
        }, attempts: 3);
    }

    public function deleteDraft(Store $store, StockCount $count, User $actor): void
    {
        DB::transaction(function () use ($store, $count, $actor): void {
            $locked = StockCount::query()
                ->whereKey($count->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== StockCountStatus::Draft) {
                throw new DomainException('فقط شمارش پیش‌نویس قابل حذف است.');
            }

            if (! $actor->canViewInventoryCosts()
                && (int) $locked->created_by !== (int) $actor->id) {
                throw new DomainException('انباردار فقط می‌تواند شمارش ایجادشده توسط خودش را حذف کند.');
            }

            $locked->items()->delete();
            $locked->delete();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(
        Store $store,
        array $filters,
        int $branchId,
    ): LengthAwarePaginator {
        return StockCount::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branchId)
            ->with(['warehouse:id,name,code', 'creator:id,name', 'items'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->orderByDesc('count_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockCount $count): array => [
                'id' => $count->id,
                'count_number' => $count->count_number,
                'count_date' => $count->count_date->toDateString(),
                'status' => $count->status->value,
                'notes' => $count->notes,
                'warehouse' => $count->warehouse->only(['id', 'code', 'name']),
                'creator' => $count->creator?->name,
                'item_count' => $count->items->count(),
                'difference_count' => $count->items
                    ->filter(fn (StockCountItem $item): bool => ! Decimal::isZero($item->difference_quantity))
                    ->count(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Store $store, StockCount $count, bool $includeCosts): array
    {
        if ((int) $count->store_id !== (int) $store->id) {
            throw new DomainException('شمارش در دسترس این فروشگاه نیست.');
        }

        $count->load(['warehouse:id,code,name', 'creator:id,name', 'poster:id,name', 'items.product']);

        return [
            'id' => $count->id,
            'count_number' => $count->count_number,
            'count_date' => $count->count_date->toDateString(),
            'status' => $count->status->value,
            'notes' => $count->notes,
            'warehouse' => $count->warehouse->only(['id', 'code', 'name']),
            'creator' => $count->creator?->name,
            'poster' => $count->poster?->name,
            'posted_at' => $count->posted_at?->toDateTimeString(),
            'items' => $count->items->sortBy('product_id')->map(function (StockCountItem $item) use ($includeCosts): array {
                $row = [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'code' => $item->product->code,
                        'name' => $item->product->name,
                        'unit' => $item->product->unit,
                    ],
                    'system_quantity' => Decimal::normalize($item->system_quantity, 3),
                    'counted_quantity' => Decimal::normalize($item->counted_quantity, 3),
                    'difference_quantity' => Decimal::normalize($item->difference_quantity, 3),
                ];

                if ($includeCosts) {
                    $row['unit_cost'] = $item->unit_cost === null
                        ? null
                        : Decimal::normalize($item->unit_cost, 4);
                    $row['adjustment_value'] = $item->unit_cost === null
                        ? '0.00'
                        : Decimal::multiply($item->difference_quantity, $item->unit_cost, 2);
                }

                return $row;
            })->values(),
        ];
    }

    /**
     * @param  array<int, array{product_id: int, counted_quantity: string|int|float, unit_cost?: string|int|float|null}>  $items
     */
    private function storeItems(StockCount $count, Warehouse $warehouse, array $items): void
    {
        foreach ($items as $item) {
            $product = Product::query()->find($item['product_id']);

            if (! $product || ! $product->is_active) {
                throw new DomainException('کالای شمارش باید فعال و معتبر باشد.');
            }

            $balance = InventoryBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            $systemQuantity = Decimal::normalize(
                $balance === null ? 0 : $balance->quantity,
                3,
            );
            $counted = Decimal::normalize($item['counted_quantity'], 3);
            $unitCost = isset($item['unit_cost']) && $item['unit_cost'] !== ''
                ? Decimal::normalize($item['unit_cost'], 4)
                : null;
            $count->items()->create([
                'product_id' => $product->id,
                'system_quantity' => $systemQuantity,
                'counted_quantity' => $counted,
                'difference_quantity' => Decimal::subtract($counted, $systemQuantity, 3),
                'unit_cost' => $unitCost,
            ]);
        }
    }

    /**
     * @return array<int, array{product_id: int, counted_quantity: string, unit_cost: string|null}>
     */
    private function normalizedItems(mixed $items, bool $allowCost): array
    {
        if (! is_array($items) || $items === []) {
            throw new DomainException('شمارش باید حداقل یک کالا داشته باشد.');
        }

        $normalized = collect($items)->map(function (mixed $item) use ($allowCost): array {
            if (! is_array($item)) {
                throw new DomainException('ردیف شمارش معتبر نیست.');
            }

            $counted = Decimal::normalize($item['counted_quantity'] ?? 0, 3);

            if (Decimal::compare($counted, '0', 3) < 0) {
                throw new DomainException('مقدار شمارش‌شده نمی‌تواند منفی باشد.');
            }

            return [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'counted_quantity' => $counted,
                'unit_cost' => $allowCost
                    && isset($item['unit_cost'])
                    && $item['unit_cost'] !== ''
                    ? Decimal::normalize($item['unit_cost'], 4)
                    : null,
            ];
        });

        if ($normalized->pluck('product_id')->duplicates()->isNotEmpty()) {
            throw new DomainException('هر کالا فقط یک بار در شمارش قابل ثبت است.');
        }

        return $normalized->sortBy('product_id')->values()->all();
    }

    private function surplusUnitCost(
        StockCountItem $item,
        ?InventoryBalance $balance,
        Product $product,
    ): string {
        $candidates = [
            $item->unit_cost,
            $balance?->average_cost,
            $product->purchase_price,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && Decimal::isPositive($candidate)) {
                return Decimal::normalize($candidate, 4);
            }
        }

        throw new DomainException('برای افزایش موجودی کالای بدون بهای مشخص، بهای واحد را وارد کنید.');
    }

    private function warehouse(Store $store, int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('store_id', $store->id)
            ->active()
            ->first();

        if (! $warehouse) {
            throw new DomainException('انبار شمارش باید فعال و متعلق به فروشگاه باشد.');
        }

        return $warehouse;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requestHash(array $data): string
    {
        $items = collect((array) ($data['items'] ?? []))
            ->map(fn (array $item): array => [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'counted_quantity' => Decimal::normalize($item['counted_quantity'] ?? 0, 3),
                'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== ''
                    ? Decimal::normalize($item['unit_cost'], 4)
                    : null,
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'warehouse_id' => (int) ($data['warehouse_id'] ?? 0),
            'count_date' => (string) ($data['count_date'] ?? ''),
            'notes' => $data['notes'] ?? null,
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertIdempotentMatch(StockCount $count, string $hash): void
    {
        if ($count->idempotency_request_hash !== $hash) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای شمارش دیگری استفاده شده است.');
        }
    }
}
