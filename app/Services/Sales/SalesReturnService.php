<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingPeriod;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Services\Returns\ReturnableQuantityService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SalesReturnService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly ReturnableQuantityService $returnableQuantities,
        private readonly AccountingPeriodLockService $periods,
    ) {}

    /**
     * @param  array<int, array{invoice_item_id: int, quantity: string|int|float}>  $items
     */
    public function createDraft(
        Invoice $salesInvoice,
        array $items,
        string $returnDate,
        ?int $createdBy = null,
        ?string $idempotencyKey = null,
    ): Invoice {
        if ($items === []) {
            throw new DomainException('برگشت فروش باید حداقل یک قلم داشته باشد.');
        }

        $canonicalItems = collect($items)
            ->map(fn (array $item): array => [
                'invoice_item_id' => (int) $item['invoice_item_id'],
                'quantity' => Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE),
            ])
            ->sortBy('invoice_item_id')
            ->values()
            ->all();
        $requestHash = hash('sha256', json_encode([
            'parent_invoice_id' => $salesInvoice->id,
            'return_date' => $returnDate,
            'created_by' => $createdBy,
            'items' => $canonicalItems,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use (
                $salesInvoice,
                $items,
                $returnDate,
                $createdBy,
                $idempotencyKey,
                $requestHash,
            ): Invoice {
                if ($idempotencyKey !== null) {
                    $existing = Invoice::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing) {
                        $this->assertMatchingIdempotentReturn($existing, $requestHash);

                        return $existing->load('items');
                    }
                }

                $original = Invoice::query()
                    ->with(['items', 'customer.party'])
                    ->lockForUpdate()
                    ->findOrFail($salesInvoice->id);

                if ($original->type !== InvoiceType::Sales
                    || ! in_array($original->status, [
                        InvoiceStatus::Confirmed,
                        InvoiceStatus::PartiallyPaid,
                        InvoiceStatus::Paid,
                    ], true)
                    || ! $original->customer?->party?->is_active
                    || (int) $original->customer->party->store_id !== (int) $original->store_id) {
                    throw new DomainException('فاکتور فروش معتبر و متعلق به فروشگاه برای برگشت یافت نشد.');
                }

                $returnDateValue = CarbonImmutable::parse($returnDate);
                $periodContext = $this->periods->lockOpenPeriod(
                    $original->store_id,
                    $original->branch_id,
                    $original->fiscal_year_id,
                    $returnDate,
                );
                $fiscalYear = $periodContext['fiscal_year'];

                if ($returnDateValue->lessThan($fiscalYear->starts_on)
                    || $returnDateValue->greaterThan($fiscalYear->ends_on)) {
                    throw new DomainException('تاریخ برگشت فروش باید داخل سال مالی فاکتور اصلی باشد.');
                }

                if ($returnDateValue->lessThan($original->invoice_date)) {
                    throw new DomainException('تاریخ برگشت فروش نمی‌تواند قبل از تاریخ فاکتور اصلی باشد.');
                }

                $return = Invoice::query()->create([
                    'store_id' => $original->store_id,
                    'branch_id' => $original->branch_id,
                    'fiscal_year_id' => $original->fiscal_year_id,
                    'invoice_number' => $this->documentNumbers->next(
                        $original->store_id,
                        $original->branch_id,
                        $original->fiscal_year_id,
                        DocumentType::SalesReturn,
                    ),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'type' => InvoiceType::SalesReturn,
                    'invoice_date' => $returnDate,
                    'customer_id' => $original->customer_id,
                    'supplier_id' => null,
                    'parent_invoice_id' => $original->id,
                    'subtotal' => '0.00',
                    'discount_total' => '0.00',
                    'tax_total' => '0.00',
                    'total' => '0.00',
                    'created_by' => $createdBy,
                ]);
                $subtotal = '0.00';
                $discountTotal = '0.00';
                $taxTotal = '0.00';
                $total = '0.00';

                foreach (array_values($items) as $index => $requested) {
                    $originalItem = $original->items->firstWhere('id', (int) $requested['invoice_item_id']);

                    if (! $originalItem || $originalItem->total_cost_snapshot === null) {
                        throw new DomainException('ردیف انتخاب‌شده برای برگشت فروش معتبر نیست.');
                    }

                    $quantity = Decimal::normalize($requested['quantity'], Decimal::QUANTITY_SCALE);

                    if (! Decimal::isPositive($quantity)) {
                        throw new DomainException('مقدار برگشت فروش باید بزرگ‌تر از صفر باشد.');
                    }

                    $state = $this->returnableQuantities->stateFor(
                        $originalItem,
                        InvoiceType::SalesReturn,
                    );
                    $remaining = $state['remaining_quantity'];

                    if (Decimal::compare($quantity, $remaining, 3) > 0) {
                        throw new DomainException('مقدار برگشت فروش از مقدار قابل برگشت ردیف بیشتر است.');
                    }

                    $isFinal = Decimal::compare($quantity, $remaining, 3) === 0;
                    $gross = Decimal::multiply($quantity, $originalItem->unit_price, 2);
                    $discount = $this->returnableQuantities->allocateMoney(
                        $originalItem->discount_amount,
                        $state['returned_discount'],
                        $quantity,
                        $originalItem->quantity,
                        $isFinal,
                    );
                    $taxable = Decimal::subtract($gross, $discount, 2);
                    $taxAmount = $this->returnableQuantities->allocateMoney(
                        $originalItem->tax_amount,
                        $state['returned_tax'],
                        $quantity,
                        $originalItem->quantity,
                        $isFinal,
                    );
                    $lineTotal = Decimal::add($taxable, $taxAmount, 2);

                    $return->items()->create([
                        'parent_invoice_item_id' => $originalItem->id,
                        'product_id' => $originalItem->product_id,
                        'product_code' => $originalItem->product_code,
                        'product_name' => $originalItem->product_name,
                        'unit' => $originalItem->unit,
                        'quantity' => $quantity,
                        'unit_price' => $originalItem->unit_price,
                        'discount_amount' => $discount,
                        'tax_rate' => $originalItem->tax_rate,
                        'tax_amount' => $taxAmount,
                        'line_total' => $lineTotal,
                        'unit_cost_snapshot' => $originalItem->unit_cost_snapshot,
                        'total_cost_snapshot' => $originalItem->total_cost_snapshot,
                        'sort_order' => $index + 1,
                    ]);

                    $subtotal = Decimal::add($subtotal, $gross, 2);
                    $discountTotal = Decimal::add($discountTotal, $discount, 2);
                    $taxTotal = Decimal::add($taxTotal, $taxAmount, 2);
                    $total = Decimal::add($total, $lineTotal, 2);
                }

                $return->forceFill([
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'total' => $total,
                ])->save();

                return $return->refresh()->load('items');
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || (string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = Invoice::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $this->assertMatchingIdempotentReturn($existing, $requestHash);

            return $existing->load('items');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function returnableItems(Invoice $salesInvoice): array
    {
        if ($salesInvoice->type !== InvoiceType::Sales
            || ! in_array($salesInvoice->status, [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
            ], true)) {
            return [];
        }

        $salesInvoice->loadMissing('items');

        return $salesInvoice->items
            ->map(fn (InvoiceItem $item): array => [
                'id' => $item->id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'returnable_quantity' => $this->returnableQuantities->remainingFor(
                    $item,
                    InvoiceType::SalesReturn,
                ),
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ])
            ->filter(fn (array $item): bool => Decimal::isPositive($item['returnable_quantity']))
            ->values()
            ->all();
    }

    public function confirm(Invoice $returnInvoice, int $warehouseId, User $approver): Invoice
    {
        return DB::transaction(function () use ($returnInvoice, $warehouseId, $approver): Invoice {
            $returnSnapshot = Invoice::query()->findOrFail($returnInvoice->id);
            $original = Invoice::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($returnSnapshot->parent_invoice_id);
            $return = Invoice::query()
                ->with(['items', 'customer.party'])
                ->lockForUpdate()
                ->findOrFail($returnInvoice->id);

            if ($return->type !== InvoiceType::SalesReturn
                || $original->type !== InvoiceType::Sales
                || (int) $return->parent_invoice_id !== (int) $original->id) {
                throw new DomainException('فاکتور اصلی برگشت فروش معتبر نیست.');
            }

            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            if ((int) $warehouse->store_id !== (int) $return->store_id
                || (int) $warehouse->branch_id !== (int) $return->branch_id) {
                throw new DomainException('انبار برگشت فروش باید متعلق به همان فروشگاه و شعبه باشد.');
            }

            if ($return->status === InvoiceStatus::Confirmed) {
                $this->assertConfirmedWarehouse(
                    $return,
                    $warehouseId,
                    InventoryMovementType::SalesReturn,
                );

                return $return->load(['items', 'parentInvoice']);
            }

            if ($return->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط برگشت فروش پیش‌نویس قابل تأیید است.');
            }

            if (! $return->customer?->party?->is_active
                || (int) $return->customer->party->store_id !== (int) $return->store_id) {
                throw new DomainException('مشتری برگشت فروش معتبر نیست.');
            }

            $period = AccountingPeriod::query()
                ->where('store_id', $return->store_id)
                ->where('fiscal_year_id', $return->fiscal_year_id)
                ->where('starts_on', '<=', $return->invoice_date)
                ->where('ends_on', '>=', $return->invoice_date)
                ->first();

            if (! $period) {
                throw new DomainException('برای تاریخ برگشت فروش دوره مالی پیدا نشد.');
            }

            $costTotal = '0.00';
            $taxTotal = '0.00';
            $subtotal = '0.00';
            $discountTotal = '0.00';
            $total = '0.00';

            foreach ($return->items as $item) {
                $originalItem = $original->items
                    ->firstWhere('id', $item->parent_invoice_item_id);

                if (! $originalItem) {
                    throw new DomainException('ردیف اصلی برگشت فروش پیدا نشد.');
                }

                $state = $this->returnableQuantities->stateFor(
                    $originalItem,
                    InvoiceType::SalesReturn,
                );
                $remaining = $state['remaining_quantity'];

                if (Decimal::compare($item->quantity, $remaining, Decimal::QUANTITY_SCALE) > 0) {
                    throw new DomainException('مقدار تأییدشده برگشت فروش از مقدار قابل برگشت ردیف بیشتر است.');
                }

                $isFinal = Decimal::compare(
                    $item->quantity,
                    $remaining,
                    Decimal::QUANTITY_SCALE,
                ) === 0;
                $gross = Decimal::multiply(
                    $item->quantity,
                    $originalItem->unit_price,
                    Decimal::MONEY_SCALE,
                );
                $discount = $this->returnableQuantities->allocateMoney(
                    $originalItem->discount_amount,
                    $state['returned_discount'],
                    $item->quantity,
                    $originalItem->quantity,
                    $isFinal,
                );
                $taxAmount = $this->returnableQuantities->allocateMoney(
                    $originalItem->tax_amount,
                    $state['returned_tax'],
                    $item->quantity,
                    $originalItem->quantity,
                    $isFinal,
                );
                $lineTotal = Decimal::add(
                    Decimal::subtract($gross, $discount, Decimal::MONEY_SCALE),
                    $taxAmount,
                    Decimal::MONEY_SCALE,
                );
                $itemCost = $this->returnableQuantities->allocateMoney(
                    $originalItem->total_cost_snapshot,
                    $state['returned_cost'],
                    $item->quantity,
                    $originalItem->quantity,
                    $isFinal,
                );
                $unitCost = Decimal::divide($itemCost, $item->quantity, 4);
                $item->forceFill([
                    'discount_amount' => $discount,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => $itemCost,
                ])->save();

                $this->inventory->receive(
                    warehouseId: $warehouseId,
                    productId: $item->product_id,
                    quantity: $item->quantity,
                    unitCost: $unitCost,
                    movementDate: $return->invoice_date->toDateString(),
                    idempotencyKey: "sales-return:{$return->id}:item:{$item->id}:warehouse:{$warehouseId}",
                    movementType: InventoryMovementType::SalesReturn,
                    source: $return,
                    createdBy: $approver->id,
                    reference: $return->invoice_number,
                    description: 'ورود انبار از برگشت فروش',
                    exactTotalCost: $itemCost,
                );

                $costTotal = Decimal::add($costTotal, $itemCost, 2);
                $taxTotal = Decimal::add($taxTotal, $taxAmount, 2);
                $subtotal = Decimal::add($subtotal, $gross, 2);
                $discountTotal = Decimal::add($discountTotal, $discount, 2);
                $total = Decimal::add($total, $lineTotal, 2);
            }

            $return->forceFill([
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $total,
            ])->save();
            $taxable = Decimal::subtract($total, $taxTotal, 2);
            $lines = [
                [
                    'account_id' => $this->settings->defaultAccountId('sales_returns', $return->store_id),
                    'debit' => $taxable,
                    'credit' => '0.00',
                    'description' => 'برگشت درآمد فروش',
                ],
                [
                    'account_id' => $this->settings->defaultAccountId('accounts_receivable', $return->store_id),
                    'party_id' => $return->customer->party_id,
                    'debit' => '0.00',
                    'credit' => $total,
                    'description' => 'کاهش مطالبات مشتری',
                ],
                [
                    'account_id' => $this->settings->defaultAccountId('inventory', $return->store_id),
                    'debit' => $costTotal,
                    'credit' => '0.00',
                    'description' => 'افزایش موجودی از برگشت فروش',
                ],
                [
                    'account_id' => $this->settings->defaultAccountId('cogs', $return->store_id),
                    'debit' => '0.00',
                    'credit' => $costTotal,
                    'description' => 'برگشت بهای تمام‌شده',
                ],
            ];

            if (Decimal::isPositive($taxTotal)) {
                $lines[] = [
                    'account_id' => $this->settings->defaultAccountId('vat_payable', $return->store_id),
                    'debit' => $taxTotal,
                    'credit' => '0.00',
                    'description' => 'برگشت مالیات فروش',
                ];
            }

            $this->posting->post(
                [
                    'store_id' => $return->store_id,
                    'branch_id' => $return->branch_id,
                    'fiscal_year_id' => $return->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $return->invoice_date->toDateString(),
                    'description' => "ثبت برگشت فروش {$return->invoice_number}",
                    'source_type' => $return->getMorphClass(),
                    'source_id' => $return->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
            );

            $return->confirmDocument();
            $return->parentInvoice?->syncSettlementStatus();

            return $return->refresh()->load(['items', 'parentInvoice']);
        });
    }

    private function assertMatchingIdempotentReturn(Invoice $existing, string $requestHash): void
    {
        if ($existing->type !== InvoiceType::SalesReturn
            || ($existing->idempotency_request_hash !== null
                && ! hash_equals($existing->idempotency_request_hash, $requestHash))) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست برگشت متفاوتی استفاده شده است.');
        }
    }

    private function assertConfirmedWarehouse(
        Invoice $return,
        int $warehouseId,
        InventoryMovementType $movementType,
    ): void {
        $warehouseIds = InventoryMovement::query()
            ->where('source_type', $return->getMorphClass())
            ->where('source_id', $return->id)
            ->where('movement_type', $movementType)
            ->pluck('warehouse_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();

        if ($warehouseIds->isEmpty()
            || $warehouseIds->count() !== 1
            || ! $warehouseIds->contains($warehouseId)) {
            throw new DomainException('این برگشت قبلاً با انبار دیگری تأیید شده است.');
        }
    }
}
