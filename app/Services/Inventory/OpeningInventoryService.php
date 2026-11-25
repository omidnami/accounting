<?php

namespace App\Services\Inventory;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\InventoryOpening;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Audit\AuditLogService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class OpeningInventoryService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AccountingPeriodLockService $periods,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function open(
        Branch $branch,
        Warehouse $warehouse,
        FiscalYear $fiscalYear,
        string $openingDate,
        array $items,
        User $actor,
        string $idempotencyKey,
    ): InventoryOpening {
        $openingDate = CarbonImmutable::parse($openingDate)->toDateString();
        $requestHash = $this->requestHash(
            $branch,
            $warehouse,
            $fiscalYear,
            $openingDate,
            $items,
        );

        try {
            return DB::transaction(function () use (
                $branch,
                $warehouse,
                $fiscalYear,
                $openingDate,
                $items,
                $actor,
                $idempotencyKey,
                $requestHash,
            ): InventoryOpening {
                $existing = InventoryOpening::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new DomainException('کلید تکرارپذیری افتتاحیه قبلاً برای درخواست متفاوتی استفاده شده است.');
                    }

                    if ($existing->status !== 'posted') {
                        throw new DomainException('افتتاحیه با همین کلید هنوز ناتمام است.');
                    }

                    return $existing->load(['items.product', 'journalEntry']);
                }

                if ((int) $warehouse->store_id !== (int) $branch->store_id
                    || (int) $warehouse->branch_id !== (int) $branch->id
                    || ! $warehouse->is_active
                    || ! $branch->is_active) {
                    throw new DomainException('انبار و شعبهٔ افتتاحیه باید فعال و متناسب باشند.');
                }

                $context = $this->periods->lockOpenPeriod(
                    (int) $branch->store_id,
                    (int) $branch->id,
                    (int) $fiscalYear->id,
                    $openingDate,
                );
                $products = $this->validateItems($items);
                $opening = InventoryOpening::query()->create([
                    'store_id' => $branch->store_id,
                    'branch_id' => $branch->id,
                    'warehouse_id' => $warehouse->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'opening_date' => $openingDate,
                    'status' => 'draft',
                    'total_cost' => '0.00',
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'created_by' => $actor->id,
                ]);
                $total = '0.00';

                foreach ($items as $index => $item) {
                    $product = $products[(int) $item['product_id']];
                    $quantity = Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE);
                    $unitCost = Decimal::normalize($item['unit_cost'], Decimal::UNIT_COST_SCALE);
                    $totalCost = Decimal::multiply($quantity, $unitCost, Decimal::MONEY_SCALE);
                    $batchId = isset($item['product_batch_id'])
                        ? (int) $item['product_batch_id']
                        : null;

                    if ($batchId !== null) {
                        $batch = ProductBatch::query()->findOrFail($batchId);

                        if ((int) $batch->product_id !== (int) $product->id) {
                            throw new DomainException('سری ساخت انتخاب‌شده متعلق به این کالا نیست.');
                        }
                    }

                    $serialNumbers = array_values(array_unique(array_map(
                        fn (mixed $serial): string => trim((string) $serial),
                        (array) ($item['serial_numbers'] ?? []),
                    )));

                    if ($product->track_serials
                        && count($serialNumbers) !== (int) round((float) $quantity)) {
                        throw new DomainException("تعداد سریال‌های {$product->name} با مقدار افتتاحیه برابر نیست.");
                    }

                    $openingItem = $opening->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'total_cost' => $totalCost,
                        'product_batch_id' => $batchId,
                        'serial_numbers' => $serialNumbers === [] ? null : $serialNumbers,
                        'sort_order' => $index + 1,
                    ]);

                    $this->inventory->receive(
                        warehouseId: $warehouse->id,
                        productId: $product->id,
                        quantity: $quantity,
                        unitCost: $unitCost,
                        movementDate: $openingDate,
                        idempotencyKey: "opening:{$opening->id}:item:{$openingItem->id}",
                        movementType: InventoryMovementType::Opening,
                        source: $opening,
                        createdBy: $actor->id,
                        reference: 'افتتاحیه موجودی',
                        description: 'ثبت موجودی اولیه',
                        exactTotalCost: $totalCost,
                        productBatchId: $batchId,
                        serialNumbers: $serialNumbers,
                    );
                    $total = Decimal::add($total, $totalCost, Decimal::MONEY_SCALE);
                }

                $entry = $this->posting->post(
                    [
                        'store_id' => $branch->store_id,
                        'branch_id' => $branch->id,
                        'fiscal_year_id' => $fiscalYear->id,
                        'accounting_period_id' => $context['period']->id,
                        'entry_kind' => JournalEntryKind::Opening,
                        'entry_date' => $openingDate,
                        'description' => 'سند افتتاحیه موجودی',
                        'source_type' => $opening->getMorphClass(),
                        'source_id' => $opening->id,
                        'created_by' => $actor->id,
                    ],
                    [
                        [
                            'account_id' => $this->settings->defaultAccountId('inventory', $branch->store_id),
                            'debit' => $total,
                            'credit' => '0.00',
                            'description' => 'افتتاحیه موجودی کالا',
                        ],
                        [
                            'account_id' => $this->settings->defaultAccountId('retained_earnings', $branch->store_id),
                            'debit' => '0.00',
                            'credit' => $total,
                            'description' => 'سرمایهٔ افتتاحیه',
                        ],
                    ],
                    $actor,
                );
                $opening->forceFill([
                    'status' => 'posted',
                    'total_cost' => $total,
                    'journal_entry_id' => $entry->id,
                ])->save();
                $this->audit->record('inventory_opening_posted', $opening);

                return $opening->refresh()->load(['items.product', 'journalEntry']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $existing = InventoryOpening::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new DomainException('کلید تکرارپذیری افتتاحیه قبلاً برای درخواست متفاوتی استفاده شده است.');
            }

            return $existing->load(['items.product', 'journalEntry']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, Product>
     */
    private function validateItems(array $items): array
    {
        if ($items === []) {
            throw new DomainException('افتتاحیه موجودی باید حداقل یک قلم داشته باشد.');
        }

        $products = Product::query()
            ->whereIn('id', array_map(fn (array $item): int => (int) $item['product_id'], $items))
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product || ! $product->is_active || ! $product->isStockItem() || $product->variants()->exists()) {
                throw new DomainException('همه قلم‌های افتتاحیه باید کالای انباری فعال و قابل فروش باشند.');
            }

            if (! Decimal::isPositive($item['quantity']) || ! Decimal::isPositive($item['unit_cost'])) {
                throw new DomainException('مقدار و بهای واحد افتتاحیه باید بزرگ‌تر از صفر باشند.');
            }
        }

        return $products->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function requestHash(
        Branch $branch,
        Warehouse $warehouse,
        FiscalYear $fiscalYear,
        string $openingDate,
        array $items,
    ): string {
        $canonicalItems = collect($items)
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE),
                'unit_cost' => Decimal::normalize($item['unit_cost'], Decimal::UNIT_COST_SCALE),
                'product_batch_id' => isset($item['product_batch_id'])
                    ? (int) $item['product_batch_id']
                    : null,
                'serial_numbers' => array_values(array_unique(array_map(
                    fn (mixed $serial): string => trim((string) $serial),
                    (array) ($item['serial_numbers'] ?? []),
                ))),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'store_id' => $branch->store_id,
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'fiscal_year_id' => $fiscalYear->id,
            'opening_date' => $openingDate,
            'items' => $canonicalItems,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{gl: string, subledger: string, difference: string}
     */
    public function reconciliation(int $storeId, int $branchId, string $asOf): array
    {
        $inventoryAccountId = $this->settings->defaultAccountId('inventory', $storeId);
        $gl = (string) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.store_id', $storeId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalEntryStatus::Posted)
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->where('journal_entry_lines.account_id', $inventoryAccountId)
            ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) AS aggregate')
            ->value('aggregate');
        $subledger = (string) InventoryMovement::query()
            ->where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->whereDate('movement_date', '<=', $asOf)
            ->selectRaw("SUM(CASE WHEN direction = '".InventoryDirection::In->value."' THEN total_cost ELSE -total_cost END) AS aggregate")
            ->value('aggregate');
        $gl = Decimal::normalize($gl, Decimal::MONEY_SCALE);
        $subledger = Decimal::normalize($subledger, Decimal::MONEY_SCALE);

        return [
            'gl' => $gl,
            'subledger' => $subledger,
            'difference' => Decimal::subtract($gl, $subledger, Decimal::MONEY_SCALE),
        ];
    }
}
