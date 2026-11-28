<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriodLockService;
use App\Services\Accounting\Contracts\PostingAuthorizationContext;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Services\Parties\PartySnapshotService;
use App\Support\Decimal;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SalesService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly CustomerReceivableService $receivables,
        private readonly AccountingPeriodLockService $periods,
        private readonly PartySnapshotService $partySnapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createDraft(
        array $attributes,
        array $items,
        ?string $idempotencyKey = null,
        bool $forceProductTaxRate = false,
    ): Invoice {
        $requestPayload = $this->draftRequestPayload($attributes, $items, ignoreProductTaxRate: $forceProductTaxRate);
        $requestHash = IdempotencyHasher::hash('sales.draft', $requestPayload);

        try {
            return DB::transaction(function () use (
                $attributes,
                $items,
                $idempotencyKey,
                $requestHash,
                $requestPayload,
                $forceProductTaxRate,
            ): Invoice {
                unset($attributes['status']);

                if ($idempotencyKey !== null) {
                    $existing = Invoice::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return $this->resolveIdempotentDraft($existing, $requestHash, $requestPayload, $forceProductTaxRate);
                    }
                }

                if ($items === []) {
                    throw new DomainException('فاکتور فروش باید حداقل یک قلم داشته باشد.');
                }

                if ($forceProductTaxRate) {
                    $items = $this->withProductTaxRates($items);
                }

                $customer = Customer::query()
                    ->with('party')
                    ->findOrFail((int) $attributes['customer_id']);

                if (! $customer->is_active || ! $customer->party?->is_active) {
                    throw new DomainException('مشتری انتخاب‌شده فعال نیست.');
                }

                if ((int) $customer->party->store_id !== (int) $attributes['store_id']) {
                    throw new DomainException('مشتری باید متعلق به فروشگاه فاکتور باشد.');
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
                        DocumentType::SalesInvoice,
                    );
                }

                $invoice = Invoice::query()->create([
                    ...$attributes,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'party_snapshot' => $this->partySnapshots->capture($customer->party),
                    'type' => InvoiceType::Sales,
                    'supplier_id' => null,
                    'customer_id' => $customer->id,
                    'subtotal' => '0.00',
                    'discount_total' => '0.00',
                    'tax_total' => '0.00',
                    'total' => '0.00',
                ]);

                $invoice->forceFill(
                    $this->replaceDraftItems($invoice, $items),
                )->save();

                return $invoice->refresh()->load('items');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null) {
                $existing = Invoice::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentDraft($existing, $requestHash, $requestPayload, $forceProductTaxRate);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateDraft(
        Invoice $salesInvoice,
        array $attributes,
        array $items,
        bool $forceProductTaxRate = false,
    ): Invoice {
        if ($items === []) {
            throw new DomainException('فاکتور فروش باید حداقل یک قلم داشته باشد.');
        }

        return DB::transaction(function () use (
            $salesInvoice,
            $attributes,
            $items,
            $forceProductTaxRate,
        ): Invoice {
            $invoice = Invoice::query()
                ->whereKey($salesInvoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->type !== InvoiceType::Sales || $invoice->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط پیش‌نویس فاکتور فروش قابل ویرایش است.');
            }

            foreach (['store_id', 'branch_id', 'fiscal_year_id'] as $scopeField) {
                if (array_key_exists($scopeField, $attributes)
                    && (int) $attributes[$scopeField] !== (int) $invoice->{$scopeField}) {
                    throw new DomainException('دامنه فاکتور فروش قابل تغییر نیست.');
                }
            }

            $customer = Customer::query()
                ->whereKey((int) $attributes['customer_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $party = Party::query()
                ->whereKey($customer->party_id)
                ->lockForUpdate()
                ->firstOrFail();
            $customer->setRelation('party', $party);

            if (! $customer->is_active || ! $party->is_active) {
                throw new DomainException('مشتری انتخاب‌شده فعال نیست.');
            }

            if ((int) $party->store_id !== (int) $invoice->store_id) {
                throw new DomainException('مشتری باید متعلق به فروشگاه فاکتور باشد.');
            }

            $partySnapshot = (int) $invoice->customer_id === (int) $customer->id
                ? $invoice->party_snapshot
                : $this->partySnapshots->capture($party);

            $this->periods->lockOpenPeriod(
                (int) $invoice->store_id,
                (int) $invoice->branch_id,
                (int) $invoice->fiscal_year_id,
                (string) $attributes['invoice_date'],
            );

            if ($forceProductTaxRate) {
                $items = $this->withProductTaxRates($items);
            }

            $updatedAttributes = [
                'store_id' => (int) $invoice->store_id,
                'branch_id' => (int) $invoice->branch_id,
                'fiscal_year_id' => (int) $invoice->fiscal_year_id,
                'customer_id' => $customer->id,
                'invoice_number' => $invoice->invoice_number,
                'parent_invoice_id' => $invoice->parent_invoice_id,
                'invoice_date' => (string) $attributes['invoice_date'],
                'due_date' => $attributes['due_date'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $invoice->created_by,
            ];
            $requestHash = IdempotencyHasher::hash('sales.draft', $this->draftRequestPayload(
                $updatedAttributes,
                $items,
                ignoreProductTaxRate: $forceProductTaxRate,
            ));
            $totals = $this->replaceDraftItems($invoice, $items);

            $invoice->forceFill([
                ...$updatedAttributes,
                ...$totals,
                'party_snapshot' => $partySnapshot,
                'idempotency_request_hash' => $requestHash,
            ])->save();

            return $invoice->refresh()->load('items');
        }, attempts: 3);
    }

    public function confirm(Invoice $invoice, int $warehouseId, User $approver, ?PostingAuthorizationContext $posContext = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $warehouseId, $approver, $posContext): Invoice {
            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->type !== InvoiceType::Sales || $locked->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط فاکتور فروش پیش‌نویس قابل تأیید است.');
            }

            $locked->load('items');
            $customer = Customer::query()
                ->with('party')
                ->whereKey($locked->customer_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->setRelation('customer', $customer);

            if (! $customer->is_active || ! $customer->party?->is_active
                || (int) $customer->party->store_id !== (int) $locked->store_id) {
                throw new DomainException('مشتری فاکتور معتبر و متعلق به فروشگاه نیست.');
            }

            if ($locked->party_snapshot === null) {
                $locked->forceFill([
                    'party_snapshot' => $this->partySnapshots->capture($customer->party),
                ])->save();
            }

            $creditIncrease = Decimal::subtract($locked->total, $posContext?->immediateAmount($locked) ?? '0.00', 2);
            $projectedCredit = Decimal::add(
                $this->receivables->customerOutstanding($customer, true),
                $creditIncrease,
                Decimal::MONEY_SCALE,
            );

            if (($posContext === null || Decimal::isPositive($creditIncrease))
                && Decimal::compare($projectedCredit, $customer->credit_limit, Decimal::MONEY_SCALE) > 0) {
                throw new DomainException('سقف اعتبار مشتری برای این فاکتور کافی نیست.');
            }

            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            if ((int) $warehouse->store_id !== (int) $locked->store_id
                || (int) $warehouse->branch_id !== (int) $locked->branch_id) {
                throw new DomainException('انبار باید متعلق به فروشگاه و شعبه فاکتور باشد.');
            }

            $period = AccountingPeriod::query()
                ->where('store_id', $locked->store_id)
                ->where('fiscal_year_id', $locked->fiscal_year_id)
                ->where('starts_on', '<=', $locked->invoice_date)
                ->where('ends_on', '>=', $locked->invoice_date)
                ->first();

            if (! $period) {
                throw new DomainException('برای تاریخ فاکتور دوره مالی پیدا نشد.');
            }

            $cogs = '0.00';

            foreach ($locked->items->sortBy('product_id')->values() as $item) {
                $product = Product::query()->findOrFail($item->product_id);

                if ($product->isStockItem()) {
                    $movement = $this->inventory->issue(
                        warehouseId: $warehouseId,
                        productId: $item->product_id,
                        quantity: $item->quantity,
                        movementDate: $locked->invoice_date->toDateString(),
                        idempotencyKey: "sale:{$locked->id}:item:{$item->id}:warehouse:{$warehouseId}",
                        movementType: InventoryMovementType::Sale,
                        source: $locked,
                        createdBy: $approver->id,
                        reference: $locked->invoice_number,
                        description: 'خروج انبار برای فروش',
                    );

                    $item->forceFill([
                        'unit_cost_snapshot' => $movement->unit_cost,
                        'total_cost_snapshot' => $movement->total_cost,
                    ])->save();
                    $cogs = Decimal::add($cogs, $movement->total_cost, Decimal::MONEY_SCALE);
                } else {
                    $item->forceFill([
                        'unit_cost_snapshot' => '0.0000',
                        'total_cost_snapshot' => '0.00',
                    ])->save();
                }
            }

            $taxTotal = Decimal::normalize($locked->tax_total, Decimal::MONEY_SCALE);
            $salesRevenue = Decimal::subtract($locked->total, $taxTotal, Decimal::MONEY_SCALE);
            $lines = [
                [
                    'account_id' => $this->settings->defaultAccountId('accounts_receivable', $locked->store_id),
                    'party_id' => $locked->customer->party_id,
                    'debit' => $locked->total,
                    'credit' => '0.00',
                    'description' => 'مطالبات از مشتری',
                ],
                [
                    'account_id' => $this->settings->defaultAccountId('sales', $locked->store_id),
                    'debit' => '0.00',
                    'credit' => $salesRevenue,
                    'description' => 'درآمد فروش',
                ],
            ];

            if (Decimal::isPositive($cogs)) {
                $lines[] = [
                    'account_id' => $this->settings->defaultAccountId('cogs', $locked->store_id),
                    'debit' => $cogs,
                    'credit' => '0.00',
                    'description' => 'بهای تمام‌شده فروش',
                ];
                $lines[] = [
                    'account_id' => $this->settings->defaultAccountId('inventory', $locked->store_id),
                    'debit' => '0.00',
                    'credit' => $cogs,
                    'description' => 'کاهش موجودی کالا',
                ];
            }

            if (Decimal::isPositive($taxTotal)) {
                $lines[] = [
                    'account_id' => $this->settings->defaultAccountId('vat_payable', $locked->store_id),
                    'debit' => '0.00',
                    'credit' => $taxTotal,
                    'description' => 'مالیات فروش',
                ];
            }

            $this->posting->post(
                [
                    'store_id' => $locked->store_id,
                    'branch_id' => $locked->branch_id,
                    'fiscal_year_id' => $locked->fiscal_year_id,
                    'accounting_period_id' => $period->id,
                    'entry_date' => $locked->invoice_date->toDateString(),
                    'description' => "ثبت فروش {$locked->invoice_number}",
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                    'created_by' => $approver->id,
                ],
                $lines,
                $approver,
                $posContext,
            );

            $locked->confirmDocument();

            return $locked->refresh()->load(['items', 'customer.party']);
        }, attempts: 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    private function replaceDraftItems(Invoice $invoice, array $items): array
    {
        $invoice->items()->delete();
        $subtotal = '0.00';
        $discountTotal = '0.00';
        $taxTotal = '0.00';
        $total = '0.00';

        foreach (array_values($items) as $index => $item) {
            $product = Product::query()->findOrFail((int) $item['product_id']);

            if (! $product->is_active) {
                throw new DomainException('کالای انتخاب‌شده غیرفعال است.');
            }

            if ($product->variants()->exists()) {
                throw new DomainException('کالای والد تنوع موجودی مستقل ندارد؛ یکی از تنوع‌ها را انتخاب کنید.');
            }

            $quantity = Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE);
            $unitPrice = Decimal::normalize($item['unit_price'], Decimal::MONEY_SCALE);

            if (! Decimal::isPositive($quantity) || ! Decimal::isPositive($unitPrice)) {
                throw new DomainException('مقدار و قیمت فروش باید بزرگ‌تر از صفر باشند.');
            }

            $gross = Decimal::multiply($quantity, $unitPrice, Decimal::MONEY_SCALE);
            $discount = Decimal::normalize($item['discount_amount'] ?? 0, Decimal::MONEY_SCALE);

            if (Decimal::compare($discount, '0', Decimal::MONEY_SCALE) < 0
                || Decimal::compare($discount, $gross, Decimal::MONEY_SCALE) > 0) {
                throw new DomainException('تخفیف ردیف نامعتبر است.');
            }

            $taxRate = Decimal::normalize($item['tax_rate'] ?? $product->tax_rate, Decimal::MONEY_SCALE);

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

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function draftRequestPayload(
        array $attributes,
        array $items,
        bool $ignoreProductTaxRate = false,
    ): array {
        $canonicalItems = collect($items)
            ->map(function (array $item) use ($ignoreProductTaxRate): array {
                $canonical = [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => Decimal::normalize($item['quantity'], Decimal::QUANTITY_SCALE),
                    'unit_price' => Decimal::normalize($item['unit_price'], Decimal::MONEY_SCALE),
                    'discount_amount' => Decimal::normalize($item['discount_amount'] ?? 0, Decimal::MONEY_SCALE),
                ];

                if (! $ignoreProductTaxRate) {
                    $canonical['tax_rate'] = isset($item['tax_rate'])
                        ? Decimal::normalize($item['tax_rate'], Decimal::MONEY_SCALE)
                        : null;
                }

                return $canonical;
            })
            ->values()
            ->all();

        $invoiceNumber = empty($attributes['invoice_number'])
            ? null
            : (string) $attributes['invoice_number'];

        return [
            'store_id' => (int) $attributes['store_id'],
            'branch_id' => (int) $attributes['branch_id'],
            'fiscal_year_id' => (int) $attributes['fiscal_year_id'],
            'customer_id' => (int) $attributes['customer_id'],
            'invoice_number' => $invoiceNumber,
            'parent_invoice_id' => isset($attributes['parent_invoice_id'])
                ? (int) $attributes['parent_invoice_id']
                : null,
            'invoice_date' => CarbonImmutable::parse($attributes['invoice_date'])->toDateString(),
            'due_date' => isset($attributes['due_date'])
                ? CarbonImmutable::parse($attributes['due_date'])->toDateString()
                : null,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => isset($attributes['created_by']) ? (int) $attributes['created_by'] : null,
            'items' => $canonicalItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedDraftPayload(Invoice $invoice, bool $ignoreProductTaxRate): array
    {
        $items = $invoice->items()->get()->map(function (InvoiceItem $item) use ($ignoreProductTaxRate): array {
            $canonical = [
                'product_id' => (int) $item->product_id,
                'quantity' => Decimal::normalize($item->quantity, Decimal::QUANTITY_SCALE),
                'unit_price' => Decimal::normalize($item->unit_price, Decimal::MONEY_SCALE),
                'discount_amount' => Decimal::normalize($item->discount_amount, Decimal::MONEY_SCALE),
            ];

            if (! $ignoreProductTaxRate) {
                $canonical['tax_rate'] = Decimal::normalize($item->tax_rate, Decimal::MONEY_SCALE);
            }

            return $canonical;
        })->values()->all();

        return [
            'store_id' => (int) $invoice->store_id,
            'branch_id' => (int) $invoice->branch_id,
            'fiscal_year_id' => (int) $invoice->fiscal_year_id,
            'customer_id' => (int) $invoice->customer_id,
            'invoice_number' => $invoice->invoice_number,
            'parent_invoice_id' => $invoice->parent_invoice_id === null ? null : (int) $invoice->parent_invoice_id,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'notes' => $invoice->notes,
            'created_by' => $invoice->created_by === null ? null : (int) $invoice->created_by,
            'items' => $items,
        ];
    }

    /**
     * Replace any client-supplied tax rate with the product's current rate.
     *
     * POS terminals must never be able to post a final invoice with an
     * arbitrary VAT rate; the catalog remains the single source of truth.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withProductTaxRates(array $items): array
    {
        $products = Product::query()
            ->whereIn('id', array_map(
                fn (array $item): int => (int) $item['product_id'],
                $items,
            ))
            ->pluck('tax_rate', 'id');

        return array_map(function (array $item) use ($products): array {
            $productId = (int) $item['product_id'];

            if (! $products->has($productId)) {
                throw new DomainException('کالای انتخاب‌شده پیدا نشد.');
            }

            $item['tax_rate'] = $products->get($productId);

            return $item;
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    private function resolveIdempotentDraft(
        Invoice $invoice,
        string $requestHash,
        array $requestPayload,
        bool $ignoreProductTaxRate,
    ): Invoice {
        if ($invoice->type !== InvoiceType::Sales) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
        }

        if ($invoice->idempotency_request_hash !== null) {
            if (! hash_equals($invoice->idempotency_request_hash, $requestHash)) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        } else {
            $persistedPayload = $this->persistedDraftPayload($invoice, $ignoreProductTaxRate);

            if ($requestPayload['invoice_number'] === null) {
                $persistedPayload['invoice_number'] = null;
            }

            if (! $ignoreProductTaxRate) {
                foreach ($requestPayload['items'] as $index => $item) {
                    if ($item['tax_rate'] === null) {
                        $persistedPayload['items'][$index]['tax_rate'] = null;
                    }
                }
            }

            if (! hash_equals(
                IdempotencyHasher::hash('sales.draft', $persistedPayload),
                $requestHash,
            )) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        }

        return $invoice->load('items');
    }
}
