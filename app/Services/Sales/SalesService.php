<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createDraft(array $attributes, array $items): Invoice
    {
        if ($items === []) {
            throw new DomainException('فاکتور فروش باید حداقل یک قلم داشته باشد.');
        }

        return DB::transaction(function () use ($attributes, $items): Invoice {
            unset($attributes['status']);

            $customer = Customer::query()
                ->with('party')
                ->findOrFail((int) $attributes['customer_id']);

            if (! $customer->is_active || ! $customer->party?->is_active) {
                throw new DomainException('مشتری انتخاب‌شده فعال نیست.');
            }

            if ((int) $customer->party->store_id !== (int) $attributes['store_id']) {
                throw new DomainException('مشتری باید متعلق به فروشگاه فاکتور باشد.');
            }

            $branch = Branch::query()->findOrFail((int) $attributes['branch_id']);
            $fiscalYear = FiscalYear::query()->findOrFail((int) $attributes['fiscal_year_id']);

            if ((int) $branch->store_id !== (int) $attributes['store_id']
                || (int) $fiscalYear->store_id !== (int) $attributes['store_id']) {
                throw new DomainException('شعبه و سال مالی باید متعلق به فروشگاه فاکتور باشند.');
            }

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
                'type' => InvoiceType::Sales,
                'supplier_id' => null,
                'customer_id' => $customer->id,
                'subtotal' => '0.00',
                'discount_total' => '0.00',
                'tax_total' => '0.00',
                'total' => '0.00',
            ]);

            $subtotal = '0.00';
            $discountTotal = '0.00';
            $taxTotal = '0.00';
            $total = '0.00';

            foreach (array_values($items) as $index => $item) {
                $product = Product::query()->findOrFail((int) $item['product_id']);

                if (! $product->is_active) {
                    throw new DomainException('کالای انتخاب‌شده غیرفعال است.');
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

            $invoice->forceFill([
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $total,
            ])->save();

            return $invoice->refresh()->load('items');
        });
    }

    public function confirm(Invoice $invoice, int $warehouseId, User $approver): Invoice
    {
        return DB::transaction(function () use ($invoice, $warehouseId, $approver): Invoice {
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

            $projectedCredit = Decimal::add(
                $this->customerOutstanding($customer),
                $locked->total,
                Decimal::MONEY_SCALE,
            );

            if (Decimal::compare($projectedCredit, $customer->credit_limit, Decimal::MONEY_SCALE) > 0) {
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

            foreach ($locked->items as $item) {
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
                [
                    'account_id' => $this->settings->defaultAccountId('cogs', $locked->store_id),
                    'debit' => $cogs,
                    'credit' => '0.00',
                    'description' => 'بهای تمام‌شده فروش',
                ],
                [
                    'account_id' => $this->settings->defaultAccountId('inventory', $locked->store_id),
                    'debit' => '0.00',
                    'credit' => $cogs,
                    'description' => 'کاهش موجودی کالا',
                ],
            ];

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
            );

            $locked->forceFill(['status' => InvoiceStatus::Confirmed])->save();

            return $locked->refresh()->load(['items', 'customer.party']);
        });
    }

    private function customerOutstanding(Customer $customer): string
    {
        $total = '0.00';
        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('type', InvoiceType::Sales)
            ->whereIn('status', [
                InvoiceStatus::Confirmed,
                InvoiceStatus::PartiallyPaid,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            $received = (string) $invoice->receiptAllocations()
                ->whereHas('receipt', fn ($query) => $query->where('status', 'confirmed'))
                ->sum('amount');
            $returned = (string) Invoice::query()
                ->where('parent_invoice_id', $invoice->id)
                ->where('type', InvoiceType::SalesReturn)
                ->where('status', InvoiceStatus::Confirmed)
                ->sum('total');
            $remaining = Decimal::subtract($invoice->total, $received, 2);
            $remaining = Decimal::subtract($remaining, $returned, 2);

            if (Decimal::compare($remaining, '0', 2) > 0) {
                $total = Decimal::add($total, $remaining, 2);
            }
        }

        return $total;
    }
}
