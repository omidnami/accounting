<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Party;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AccountingPeriodLockService $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, mixed>  $additionalCosts
     */
    public function createDraft(
        array $attributes,
        array $items,
        ?string $idempotencyKey = null,
        array $additionalCosts = [],
    ): Invoice {
        if ($items === []) {
            throw new DomainException('فاکتور خرید باید حداقل یک قلم داشته باشد.');
        }

        $requestHash = $idempotencyKey === null
            ? null
            : $this->draftRequestHash($attributes, $items, $additionalCosts);

        try {
            return DB::transaction(function () use (
                $attributes,
                $items,
                $idempotencyKey,
                $requestHash,
                $additionalCosts,
            ): Invoice {
                unset($attributes['status']);

                if ($idempotencyKey !== null) {
                    $existing = Invoice::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return $this->resolveIdempotentDraft($existing, $requestHash);
                    }
                }

                $supplier = Supplier::query()
                    ->with('party')
                    ->findOrFail((int) $attributes['supplier_id']);

                if (! $supplier->is_active || ! $supplier->party?->is_active) {
                    throw new DomainException('تأمین‌کننده انتخاب‌شده فعال نیست.');
                }

                if ((int) $supplier->party->store_id !== (int) $attributes['store_id']) {
                    throw new DomainException('تأمین‌کننده باید متعلق به فروشگاه فاکتور باشد.');
                }

                $this->periods->lockOpenPeriod(
                    (int) $attributes['store_id'],
                    (int) $attributes['branch_id'],
                    (int) $attributes['fiscal_year_id'],
                    (string) $attributes['invoice_date'],
                );

                if (empty($attributes['invoice_number'])) {
                    $attributes['invoice_number'] = $this->documentNumbers->next(
                        (int) $attributes['store_id'],
                        (int) $attributes['branch_id'],
                        (int) $attributes['fiscal_year_id'],
                        DocumentType::PurchaseInvoice,
                    );
                }

                $invoice = Invoice::query()->create([
                    ...$attributes,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'type' => InvoiceType::Purchase,
                    'customer_id' => null,
                    'supplier_id' => $supplier->id,
                    'subtotal' => '0.00',
                    'discount_total' => '0.00',
                    'tax_total' => '0.00',
                    'additional_cost_total' => '0.00',
                    'total' => '0.00',
                ]);

                $subtotal = '0.00';
                $discountTotal = '0.00';
                $taxTotal = '0.00';
                $total = '0.00';
                $normalizedAdditionalCosts = $this->normalizedAdditionalCosts($additionalCosts);

                foreach ($normalizedAdditionalCosts as $index => $cost) {
                    $invoice->additionalCosts()->create([
                        'description' => $cost['description'],
                        'amount' => $cost['amount'],
                        'sort_order' => $index + 1,
                    ]);
                }

                foreach (array_values($items) as $index => $item) {
                    $product = Product::query()->findOrFail((int) $item['product_id']);

                    if (! $product->is_active) {
                        throw new DomainException('کالای انتخاب‌شده غیرفعال است.');
                    }

                    $quantity = Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE);

                    if (! Decimal::isPositive($quantity)) {
                        throw new DomainException('مقدار هر قلم خرید باید بزرگ‌تر از صفر باشد.');
                    }

                    $unitPrice = Decimal::normalize($item['unit_price'], Decimal::MONEY_SCALE);

                    if (! Decimal::isPositive($unitPrice)) {
                        throw new DomainException('قیمت واحد هر قلم خرید باید بزرگ‌تر از صفر باشد.');
                    }

                    $gross = Decimal::multiply($quantity, $unitPrice, Decimal::MONEY_SCALE);
                    $discount = Decimal::normalize(
                        $item['discount_amount'] ?? 0,
                        Decimal::MONEY_SCALE,
                    );

                    if (Decimal::compare($discount, '0', Decimal::MONEY_SCALE) < 0) {
                        throw new DomainException('تخفیف ردیف نمی‌تواند منفی باشد.');
                    }

                    if (Decimal::compare($discount, $gross, Decimal::MONEY_SCALE) > 0) {
                        throw new DomainException('تخفیف ردیف نمی‌تواند از مبلغ ردیف بیشتر باشد.');
                    }

                    $taxRate = Decimal::normalize(
                        $item['tax_rate'] ?? $product->tax_rate,
                        Decimal::MONEY_SCALE,
                    );

                    if (Decimal::compare($taxRate, '0', Decimal::MONEY_SCALE) < 0
                        || Decimal::compare($taxRate, '100', Decimal::MONEY_SCALE) > 0) {
                        throw new DomainException('نرخ مالیات باید بین صفر و صد درصد باشد.');
                    }

                    $taxable = Decimal::subtract($gross, $discount, Decimal::MONEY_SCALE);
                    $taxAmount = Decimal::divide(
                        Decimal::multiply($taxable, $taxRate, 4),
                        '100',
                        Decimal::MONEY_SCALE,
                    );
                    $lineTotal = Decimal::add($taxable, $taxAmount, Decimal::MONEY_SCALE);

                    $invoice->items()->create([
                        'product_id' => $product->id,
                        'product_code' => $product->code,
                        'product_name' => $product->name,
                        'unit' => $product->unit,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount_amount' => $discount,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'line_total' => $lineTotal,
                        'sort_order' => $index + 1,
                    ]);

                    $subtotal = Decimal::add($subtotal, $gross, Decimal::MONEY_SCALE);
                    $discountTotal = Decimal::add($discountTotal, $discount, Decimal::MONEY_SCALE);
                    $taxTotal = Decimal::add($taxTotal, $taxAmount, Decimal::MONEY_SCALE);
                    $total = Decimal::add($total, $lineTotal, Decimal::MONEY_SCALE);
                }

                $invoice->forceFill([
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'additional_cost_total' => array_reduce(
                        $normalizedAdditionalCosts,
                        fn (string $sum, array $cost): string => Decimal::add(
                            $sum,
                            $cost['amount'],
                            Decimal::MONEY_SCALE,
                        ),
                        '0.00',
                    ),
                    'total' => Decimal::add(
                        $total,
                        array_reduce(
                            $normalizedAdditionalCosts,
                            fn (string $sum, array $cost): string => Decimal::add(
                                $sum,
                                $cost['amount'],
                                Decimal::MONEY_SCALE,
                            ),
                            '0.00',
                        ),
                        Decimal::MONEY_SCALE,
                    ),
                ])->save();

                return $invoice->refresh()->load(['items', 'additionalCosts']);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null) {
                $existing = Invoice::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentDraft($existing, $requestHash);
                }
            }

            throw $exception;
        }
    }

    public function confirm(Invoice $invoice, int $warehouseId, User $approver): Invoice
    {
        return DB::transaction(function () use ($invoice, $warehouseId, $approver): Invoice {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->type !== InvoiceType::Purchase) {
                throw new DomainException('فقط فاکتور خرید قابل تأیید با این سرویس است.');
            }

            if ($lockedInvoice->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط فاکتور خرید پیش‌نویس قابل تأیید است.');
            }

            $lockedInvoice->load(['items', 'additionalCosts', 'branch', 'fiscalYear']);
            $supplier = Supplier::query()
                ->whereKey($lockedInvoice->supplier_id)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw new DomainException('تأمین‌کننده فاکتور معتبر و فعال نیست.');
            }

            $party = Party::query()
                ->whereKey($supplier->party_id)
                ->lockForUpdate()
                ->first();
            $supplier->setRelation('party', $party);

            if (! $supplier->is_active || ! $party?->is_active) {
                throw new DomainException('تأمین‌کننده فاکتور معتبر و فعال نیست.');
            }

            if ((int) $party->store_id !== (int) $lockedInvoice->store_id) {
                throw new DomainException('تأمین‌کننده باید متعلق به فروشگاه فاکتور باشد.');
            }

            $period = AccountingPeriod::query()
                ->where('store_id', $lockedInvoice->store_id)
                ->where('fiscal_year_id', $lockedInvoice->fiscal_year_id)
                ->where('starts_on', '<=', $lockedInvoice->invoice_date)
                ->where('ends_on', '>=', $lockedInvoice->invoice_date)
                ->first();

            if (! $period) {
                throw new DomainException('برای تاریخ فاکتور دوره مالی باز پیدا نشد.');
            }

            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            if ((int) $warehouse->store_id !== (int) $lockedInvoice->store_id
                || (int) $warehouse->branch_id !== (int) $lockedInvoice->branch_id) {
                throw new DomainException('انبار باید متعلق به فروشگاه و شعبه فاکتور خرید باشد.');
            }

            $inventoryTotal = '0.00';
            $taxTotal = '0.00';
            $additionalCostTotal = Decimal::normalize(
                $lockedInvoice->additional_cost_total,
                Decimal::MONEY_SCALE,
            );
            $additionalCostRowsTotal = Decimal::normalize(
                (string) $lockedInvoice->additionalCosts->sum('amount'),
                Decimal::MONEY_SCALE,
            );

            if (Decimal::compare(
                $additionalCostTotal,
                $additionalCostRowsTotal,
                Decimal::MONEY_SCALE,
            ) !== 0) {
                throw new DomainException('جمع هزینه‌های جانبی خرید با فاکتور سازگار نیست.');
            }
            $additionalCostAllocations = $this->allocateAdditionalCosts(
                $lockedInvoice->items->sortBy('id')->values(),
                $additionalCostTotal,
            );

            foreach ($lockedInvoice->items->sortBy('product_id')->values() as $item) {
                $baseItemCost = Decimal::subtract(
                    $item->line_total,
                    $item->tax_amount,
                    Decimal::MONEY_SCALE,
                );
                $itemCost = Decimal::add(
                    $baseItemCost,
                    $additionalCostAllocations[$item->id] ?? '0.00',
                    Decimal::MONEY_SCALE,
                );
                $unitCost = Decimal::divide(
                    $itemCost,
                    $item->quantity,
                    Decimal::UNIT_COST_SCALE,
                );

                $this->inventory->receive(
                    warehouseId: $warehouseId,
                    productId: $item->product_id,
                    quantity: $item->quantity,
                    unitCost: $unitCost,
                    movementDate: $lockedInvoice->invoice_date->toDateString(),
                    idempotencyKey: "purchase:{$lockedInvoice->id}:item:{$item->id}:warehouse:{$warehouseId}",
                    movementType: InventoryMovementType::Purchase,
                    source: $lockedInvoice,
                    createdBy: $approver->id,
                    reference: $lockedInvoice->invoice_number,
                    description: 'ورود انبار از فاکتور خرید',
                    exactTotalCost: $itemCost,
                );

                $inventoryTotal = Decimal::add($inventoryTotal, $itemCost, Decimal::MONEY_SCALE);
                $taxTotal = Decimal::add($taxTotal, $item->tax_amount, Decimal::MONEY_SCALE);
            }

            $additionalCostTotal = Decimal::normalize(
                $lockedInvoice->additional_cost_total,
                Decimal::MONEY_SCALE,
            );
            $lines = [
                [
                    'account_id' => $this->settings->defaultAccountId('inventory', $lockedInvoice->store_id),
                    'debit' => $inventoryTotal,
                    'credit' => '0.00',
                    'description' => 'افزایش موجودی کالا و هزینه‌های خرید',
                ],
            ];

            if (Decimal::isPositive($taxTotal)) {
                $lines[] = [
                    'account_id' => $this->settings->defaultAccountId('vat_receivable', $lockedInvoice->store_id),
                    'debit' => $taxTotal,
                    'credit' => '0.00',
                    'description' => 'مالیات خرید قابل بازیافت',
                ];
            }

            $lines[] = [
                'account_id' => $this->settings->defaultAccountId('accounts_payable', $lockedInvoice->store_id),
                'party_id' => $supplier->party_id,
                'debit' => '0.00',
                'credit' => $lockedInvoice->total,
                'description' => 'بدهی به تأمین‌کننده',
            ];

            $this->posting->post(
                [
                    'store_id' => $lockedInvoice->store_id,
                    'branch_id' => $lockedInvoice->branch_id,
                    'fiscal_year_id' => $lockedInvoice->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $lockedInvoice->invoice_date->toDateString(),
                    'description' => "ثبت خرید {$lockedInvoice->invoice_number}",
                    'source_type' => $lockedInvoice->getMorphClass(),
                    'source_id' => $lockedInvoice->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
            );

            $lockedInvoice->confirmDocument();

            return $lockedInvoice->refresh()->load(['items', 'additionalCosts', 'supplier.party']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, mixed>  $additionalCosts
     */
    private function draftRequestHash(
        array $attributes,
        array $items,
        array $additionalCosts = [],
    ): string {
        $canonicalItems = collect($items)
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE),
                'unit_price' => Decimal::normalize($item['unit_price'], Decimal::MONEY_SCALE),
                'discount_amount' => Decimal::normalize($item['discount_amount'] ?? 0, Decimal::MONEY_SCALE),
                'tax_rate' => isset($item['tax_rate'])
                    ? Decimal::normalize($item['tax_rate'], Decimal::MONEY_SCALE)
                    : null,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'store_id' => (int) $attributes['store_id'],
            'branch_id' => (int) $attributes['branch_id'],
            'fiscal_year_id' => (int) $attributes['fiscal_year_id'],
            'supplier_id' => (int) $attributes['supplier_id'],
            'invoice_date' => (string) $attributes['invoice_date'],
            'due_date' => $attributes['due_date'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => isset($attributes['created_by']) ? (int) $attributes['created_by'] : null,
            'items' => $canonicalItems,
            'additional_costs' => $this->normalizedAdditionalCosts($additionalCosts),
        ], JSON_THROW_ON_ERROR));
    }

    private function resolveIdempotentDraft(Invoice $invoice, string $requestHash): Invoice
    {
        if ($invoice->type !== InvoiceType::Purchase
            || $invoice->idempotency_request_hash === null
            || ! hash_equals($invoice->idempotency_request_hash, $requestHash)) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
        }

        return $invoice->load(['items', 'additionalCosts']);
    }

    /**
     * @param  array<int, mixed>  $costs
     * @return array<int, array{description: string, amount: string}>
     */
    private function normalizedAdditionalCosts(array $costs): array
    {
        return collect($costs)->map(function (mixed $cost): array {
            if (! is_array($cost) || trim((string) ($cost['description'] ?? '')) === '') {
                throw new DomainException('شرح هزینهٔ جانبی خرید الزامی است.');
            }

            $amount = Decimal::normalize($cost['amount'] ?? 0, Decimal::MONEY_SCALE);

            if (! Decimal::isPositive($amount)) {
                throw new DomainException('مبلغ هزینهٔ جانبی خرید باید بزرگ‌تر از صفر باشد.');
            }

            return [
                'description' => trim((string) $cost['description']),
                'amount' => $amount,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return array<int, string>
     */
    private function allocateAdditionalCosts($items, string $total): array
    {
        if (Decimal::isZero($total)) {
            return [];
        }

        $weights = $items->mapWithKeys(fn (InvoiceItem $item): array => [
            $item->id => Decimal::subtract(
                $item->line_total,
                $item->tax_amount,
                Decimal::MONEY_SCALE,
            ),
        ]);
        $weightTotal = $weights->reduce(
            fn (string $sum, string $weight): string => Decimal::add($sum, $weight, Decimal::MONEY_SCALE),
            '0.00',
        );

        if (! Decimal::isPositive($weightTotal)) {
            throw new DomainException('مبنای تخصیص هزینهٔ جانبی خرید معتبر نیست.');
        }

        $allocations = [];
        $remaining = $total;
        $lastId = $items->last()->id;

        foreach ($weights as $itemId => $weight) {
            if ($itemId === $lastId) {
                $allocations[$itemId] = $remaining;
                break;
            }

            $allocation = Decimal::divide(
                Decimal::multiply($total, $weight, 4),
                $weightTotal,
                Decimal::MONEY_SCALE,
            );
            $allocations[$itemId] = $allocation;
            $remaining = Decimal::subtract($remaining, $allocation, Decimal::MONEY_SCALE);
        }

        return $allocations;
    }
}
