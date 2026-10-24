<?php

namespace App\Services\Purchasing;

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

class PurchaseReturnService
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
        Invoice $purchaseInvoice,
        array $items,
        string $returnDate,
        ?int $createdBy = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): Invoice {
        if ($items === []) {
            throw new DomainException('برگشت خرید باید حداقل یک قلم داشته باشد.');
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
            'parent_invoice_id' => $purchaseInvoice->id,
            'return_date' => $returnDate,
            'created_by' => $createdBy,
            'notes' => $notes,
            'items' => $canonicalItems,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use (
                $purchaseInvoice,
                $items,
                $returnDate,
                $createdBy,
                $notes,
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
                    ->with(['items', 'supplier.party'])
                    ->lockForUpdate()
                    ->findOrFail($purchaseInvoice->id);

                if ($original->type !== InvoiceType::Purchase
                    || ! in_array($original->status, [
                        InvoiceStatus::Confirmed,
                        InvoiceStatus::PartiallyPaid,
                        InvoiceStatus::Paid,
                    ], true)) {
                    throw new DomainException('فاکتور خرید باید تأییدشده باشد تا برگشت ثبت شود.');
                }

                if (! $original->supplier?->party?->is_active) {
                    throw new DomainException('طرف‌حساب فاکتور خرید فعال نیست.');
                }

                if ((int) $original->supplier->party->store_id !== (int) $original->store_id) {
                    throw new DomainException('طرف‌حساب باید متعلق به فروشگاه فاکتور باشد.');
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
                    throw new DomainException('تاریخ برگشت خرید باید داخل سال مالی فاکتور اصلی باشد.');
                }

                if ($returnDateValue->lessThan($original->invoice_date)) {
                    throw new DomainException('تاریخ برگشت خرید نمی‌تواند قبل از تاریخ فاکتور اصلی باشد.');
                }

                $returnInvoice = Invoice::query()->create([
                    'store_id' => $original->store_id,
                    'branch_id' => $original->branch_id,
                    'fiscal_year_id' => $original->fiscal_year_id,
                    'invoice_number' => $this->documentNumbers->next(
                        $original->store_id,
                        $original->branch_id,
                        $original->fiscal_year_id,
                        DocumentType::PurchaseReturn,
                    ),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'type' => InvoiceType::PurchaseReturn,
                    'invoice_date' => $returnDate,
                    'customer_id' => null,
                    'supplier_id' => $original->supplier_id,
                    'parent_invoice_id' => $original->id,
                    'subtotal' => '0.00',
                    'discount_total' => '0.00',
                    'tax_total' => '0.00',
                    'total' => '0.00',
                    'notes' => $notes,
                    'created_by' => $createdBy,
                ]);

                $subtotal = '0.00';
                $discountTotal = '0.00';
                $taxTotal = '0.00';
                $total = '0.00';

                foreach (array_values($items) as $index => $requested) {
                    $originalItem = $original->items->firstWhere('id', (int) $requested['invoice_item_id']);

                    if (! $originalItem) {
                        throw new DomainException('ردیف انتخاب‌شده متعلق به فاکتور خرید نیست.');
                    }

                    $quantity = Decimal::normalize($requested['quantity'], Decimal::QUANTITY_SCALE);

                    if (! Decimal::isPositive($quantity)) {
                        throw new DomainException('مقدار برگشت باید بزرگ‌تر از صفر باشد.');
                    }

                    $state = $this->returnableQuantities->stateFor(
                        $originalItem,
                        InvoiceType::PurchaseReturn,
                    );
                    $remaining = $state['remaining_quantity'];

                    if (Decimal::compare($quantity, $remaining, Decimal::QUANTITY_SCALE) > 0) {
                        throw new DomainException('مقدار برگشت از مقدار قابل برگشت ردیف بیشتر است.');
                    }

                    $isFinal = Decimal::compare($quantity, $remaining, Decimal::QUANTITY_SCALE) === 0;
                    $gross = Decimal::multiply($quantity, $originalItem->unit_price, Decimal::MONEY_SCALE);
                    $discount = $this->returnableQuantities->allocateMoney(
                        $originalItem->discount_amount,
                        $state['returned_discount'],
                        $quantity,
                        $originalItem->quantity,
                        $isFinal,
                    );
                    $taxable = Decimal::subtract($gross, $discount, Decimal::MONEY_SCALE);
                    $taxAmount = $this->returnableQuantities->allocateMoney(
                        $originalItem->tax_amount,
                        $state['returned_tax'],
                        $quantity,
                        $originalItem->quantity,
                        $isFinal,
                    );
                    $lineTotal = Decimal::add($taxable, $taxAmount, Decimal::MONEY_SCALE);

                    $returnInvoice->items()->create([
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
                        'sort_order' => $index + 1,
                    ]);

                    $subtotal = Decimal::add($subtotal, $gross, Decimal::MONEY_SCALE);
                    $discountTotal = Decimal::add($discountTotal, $discount, Decimal::MONEY_SCALE);
                    $taxTotal = Decimal::add($taxTotal, $taxAmount, Decimal::MONEY_SCALE);
                    $total = Decimal::add($total, $lineTotal, Decimal::MONEY_SCALE);
                }

                $returnInvoice->forceFill([
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'total' => $total,
                ])->save();

                return $returnInvoice->refresh()->load('items');
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
     * @param  array<int, array{invoice_item_id: int, quantity: string|int|float}>  $items
     */
    public function updateDraft(
        Invoice $returnInvoice,
        array $items,
        string $returnDate,
        ?string $notes = null,
    ): Invoice {
        if ($items === []) {
            throw new DomainException('برگشت خرید باید حداقل یک قلم داشته باشد.');
        }

        return DB::transaction(function () use (
            $returnInvoice,
            $items,
            $returnDate,
            $notes,
        ): Invoice {
            $returnSnapshot = Invoice::query()->findOrFail($returnInvoice->id);
            $original = Invoice::query()
                ->with(['items', 'supplier.party'])
                ->lockForUpdate()
                ->findOrFail($returnSnapshot->parent_invoice_id);
            $return = Invoice::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($returnInvoice->id);

            if ($return->type !== InvoiceType::PurchaseReturn
                || $original->type !== InvoiceType::Purchase
                || (int) $return->parent_invoice_id !== (int) $original->id
                || (int) $return->store_id !== (int) $original->store_id
                || (int) $return->branch_id !== (int) $original->branch_id
                || $return->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط پیش‌نویس برگشت خرید قابل ویرایش است.');
            }

            if (! in_array($original->status, [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
            ], true)
                || ! $original->supplier?->party?->is_active
                || (int) $original->supplier->party->store_id !== (int) $original->store_id) {
                throw new DomainException('فاکتور خرید معتبر و متعلق به فروشگاه برای برگشت یافت نشد.');
            }

            $returnDateValue = CarbonImmutable::parse($returnDate);
            $this->periods->lockOpenPeriod(
                $original->store_id,
                $original->branch_id,
                $original->fiscal_year_id,
                $returnDate,
            );

            if ($returnDateValue->lessThan($original->invoice_date)
                || $returnDateValue->lessThan($original->fiscalYear->starts_on)
                || $returnDateValue->greaterThan($original->fiscalYear->ends_on)) {
                throw new DomainException('تاریخ برگشت خرید باید داخل سال مالی و بعد از فاکتور اصلی باشد.');
            }

            $return->items()->delete();
            $subtotal = '0.00';
            $discountTotal = '0.00';
            $taxTotal = '0.00';
            $total = '0.00';

            foreach (array_values($items) as $index => $requested) {
                $originalItem = $original->items
                    ->firstWhere('id', (int) $requested['invoice_item_id']);

                if (! $originalItem) {
                    throw new DomainException('ردیف انتخاب‌شده متعلق به فاکتور خرید نیست.');
                }

                $quantity = Decimal::normalize($requested['quantity'], Decimal::QUANTITY_SCALE);

                if (! Decimal::isPositive($quantity)) {
                    throw new DomainException('مقدار برگشت باید بزرگ‌تر از صفر باشد.');
                }

                $state = $this->returnableQuantities->stateFor(
                    $originalItem,
                    InvoiceType::PurchaseReturn,
                );
                $remaining = $state['remaining_quantity'];

                if (Decimal::compare($quantity, $remaining, Decimal::QUANTITY_SCALE) > 0) {
                    throw new DomainException('مقدار برگشت از مقدار قابل برگشت ردیف بیشتر است.');
                }

                $isFinal = Decimal::compare($quantity, $remaining, Decimal::QUANTITY_SCALE) === 0;
                $gross = Decimal::multiply($quantity, $originalItem->unit_price, Decimal::MONEY_SCALE);
                $discount = $this->returnableQuantities->allocateMoney(
                    $originalItem->discount_amount,
                    $state['returned_discount'],
                    $quantity,
                    $originalItem->quantity,
                    $isFinal,
                );
                $taxAmount = $this->returnableQuantities->allocateMoney(
                    $originalItem->tax_amount,
                    $state['returned_tax'],
                    $quantity,
                    $originalItem->quantity,
                    $isFinal,
                );
                $lineTotal = Decimal::add(
                    Decimal::subtract($gross, $discount, Decimal::MONEY_SCALE),
                    $taxAmount,
                    Decimal::MONEY_SCALE,
                );

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
                    'sort_order' => $index + 1,
                ]);

                $subtotal = Decimal::add($subtotal, $gross, Decimal::MONEY_SCALE);
                $discountTotal = Decimal::add($discountTotal, $discount, Decimal::MONEY_SCALE);
                $taxTotal = Decimal::add($taxTotal, $taxAmount, Decimal::MONEY_SCALE);
                $total = Decimal::add($total, $lineTotal, Decimal::MONEY_SCALE);
            }

            $return->forceFill([
                'invoice_date' => $returnDate,
                'notes' => $notes,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'idempotency_request_hash' => hash('sha256', json_encode([
                    'parent_invoice_id' => $original->id,
                    'return_date' => $returnDate,
                    'notes' => $notes,
                    'items' => $items,
                ], JSON_THROW_ON_ERROR)),
            ])->save();

            return $return->refresh()->load('items');
        }, attempts: 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function returnableItems(Invoice $purchaseInvoice): array
    {
        if ($purchaseInvoice->type !== InvoiceType::Purchase
            || ! in_array($purchaseInvoice->status, [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
            ], true)) {
            return [];
        }

        $purchaseInvoice->loadMissing('items');

        return $purchaseInvoice->items
            ->map(fn (InvoiceItem $item): array => [
                'id' => $item->id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'returnable_quantity' => $this->returnableQuantities->remainingFor(
                    $item,
                    InvoiceType::PurchaseReturn,
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
                ->with(['items', 'parentInvoice', 'supplier.party'])
                ->lockForUpdate()
                ->findOrFail($returnInvoice->id);

            if ($return->type !== InvoiceType::PurchaseReturn
                || $original->type !== InvoiceType::Purchase
                || (int) $return->parent_invoice_id !== (int) $original->id) {
                throw new DomainException('فاکتور اصلی برگشت خرید معتبر نیست.');
            }

            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            if ((int) $warehouse->store_id !== (int) $return->store_id
                || (int) $warehouse->branch_id !== (int) $return->branch_id) {
                throw new DomainException('انبار باید متعلق به فروشگاه و شعبه سند برگشت باشد.');
            }

            if ($return->status === InvoiceStatus::Confirmed) {
                $this->assertConfirmedWarehouse(
                    $return,
                    $warehouseId,
                    InventoryMovementType::PurchaseReturn,
                );

                return $return->load(['items', 'parentInvoice']);
            }

            if ($return->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط برگشت خرید پیش‌نویس قابل تأیید است.');
            }

            $supplier = $return->supplier;

            if (! $supplier?->party?->is_active) {
                throw new DomainException('طرف‌حساب برگشت خرید فعال نیست.');
            }

            if ((int) $supplier->party->store_id !== (int) $return->store_id) {
                throw new DomainException('طرف‌حساب باید متعلق به فروشگاه برگشت باشد.');
            }

            $period = AccountingPeriod::query()
                ->where('store_id', $return->store_id)
                ->where('fiscal_year_id', $return->fiscal_year_id)
                ->where('starts_on', '<=', $return->invoice_date)
                ->where('ends_on', '>=', $return->invoice_date)
                ->first();

            if (! $period) {
                throw new DomainException('برای تاریخ برگشت، دوره مالی پیدا نشد.');
            }

            $inventoryCost = '0.00';
            $taxTotal = '0.00';
            $subtotal = '0.00';
            $discountTotal = '0.00';
            $total = '0.00';

            foreach ($return->items as $item) {
                $originalItem = $original->items
                    ->firstWhere('id', $item->parent_invoice_item_id);

                if (! $originalItem) {
                    throw new DomainException('ردیف اصلی برگشت خرید پیدا نشد.');
                }

                $state = $this->returnableQuantities->stateFor(
                    $originalItem,
                    InvoiceType::PurchaseReturn,
                );
                $remaining = $state['remaining_quantity'];

                if (Decimal::compare($item->quantity, $remaining, Decimal::QUANTITY_SCALE) > 0) {
                    throw new DomainException('مقدار تأییدشده برگشت خرید از مقدار قابل برگشت ردیف بیشتر است.');
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
                $item->forceFill([
                    'discount_amount' => $discount,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ])->save();

                $movement = $this->inventory->issue(
                    warehouseId: $warehouseId,
                    productId: $item->product_id,
                    quantity: $item->quantity,
                    movementDate: $return->invoice_date->toDateString(),
                    idempotencyKey: "purchase-return:{$return->id}:item:{$item->id}:warehouse:{$warehouseId}",
                    movementType: InventoryMovementType::PurchaseReturn,
                    source: $return,
                    createdBy: $approver->id,
                    reference: $return->invoice_number,
                    description: 'خروج انبار برای برگشت خرید',
                );

                $inventoryCost = Decimal::add($inventoryCost, $movement->total_cost, Decimal::MONEY_SCALE);
                $taxTotal = Decimal::add($taxTotal, $taxAmount, Decimal::MONEY_SCALE);
                $subtotal = Decimal::add($subtotal, $gross, Decimal::MONEY_SCALE);
                $discountTotal = Decimal::add($discountTotal, $discount, Decimal::MONEY_SCALE);
                $total = Decimal::add($total, $lineTotal, Decimal::MONEY_SCALE);
            }

            $return->forceFill([
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $total,
            ])->save();
            $difference = Decimal::subtract(
                Decimal::subtract($total, $inventoryCost, Decimal::MONEY_SCALE),
                $taxTotal,
                Decimal::MONEY_SCALE,
            );
            $lines = [
                [
                    'account_id' => $this->settings->defaultAccountId('accounts_payable', $return->store_id),
                    'party_id' => $supplier->party_id,
                    'debit' => $total,
                    'credit' => '0.00',
                    'description' => 'کاهش بدهی تأمین‌کننده',
                ],
                [
                    'account_id' => $this->settings->defaultAccountId('inventory', $return->store_id),
                    'debit' => '0.00',
                    'credit' => $inventoryCost,
                    'description' => 'کاهش موجودی انبار',
                ],
            ];

            if (Decimal::isPositive($taxTotal)) {
                $lines[] = [
                    'account_id' => $this->settings->defaultAccountId('vat_receivable', $return->store_id),
                    'debit' => '0.00',
                    'credit' => $taxTotal,
                    'description' => 'برگشت مالیات خرید',
                ];
            }

            if (! Decimal::isZero($difference)) {
                $differenceAccount = $this->settings->defaultAccountId(
                    'purchase_return_difference',
                    $return->store_id,
                );

                $lines[] = Decimal::isPositive($difference)
                    ? [
                        'account_id' => $differenceAccount,
                        'debit' => $difference,
                        'credit' => '0.00',
                        'description' => 'اختلاف قیمت برگشت خرید',
                    ]
                    : [
                        'account_id' => $differenceAccount,
                        'debit' => '0.00',
                        'credit' => ltrim($difference, '-'),
                        'description' => 'اختلاف قیمت برگشت خرید',
                    ];
            }

            $this->posting->post(
                [
                    'store_id' => $return->store_id,
                    'branch_id' => $return->branch_id,
                    'fiscal_year_id' => $return->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $return->invoice_date->toDateString(),
                    'description' => "ثبت برگشت خرید {$return->invoice_number}",
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
        if ($existing->type !== InvoiceType::PurchaseReturn
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
