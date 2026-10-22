<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\SettingService;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesReturnService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly SettingService $settings,
    ) {}

    /**
     * @param  array<int, array{invoice_item_id: int, quantity: string|int|float}>  $items
     */
    public function createDraft(Invoice $salesInvoice, array $items, string $returnDate, ?int $createdBy = null): Invoice
    {
        if ($items === []) {
            throw new DomainException('برگشت فروش باید حداقل یک قلم داشته باشد.');
        }

        return DB::transaction(function () use ($salesInvoice, $items, $returnDate, $createdBy): Invoice {
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

            $fiscalYear = $original->fiscalYear()->firstOrFail();
            $returnDateValue = CarbonImmutable::parse($returnDate);

            if ($returnDateValue->lessThan($fiscalYear->starts_on)
                || $returnDateValue->greaterThan($fiscalYear->ends_on)) {
                throw new DomainException('تاریخ برگشت فروش باید داخل سال مالی فاکتور اصلی باشد.');
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

                $returned = InvoiceItem::query()
                    ->where('parent_invoice_item_id', $originalItem->id)
                    ->whereHas('invoice', function ($query): void {
                        $query->where('type', InvoiceType::SalesReturn)
                            ->where('status', InvoiceStatus::Confirmed);
                    })
                    ->sum('quantity');
                $remaining = Decimal::subtract($originalItem->quantity, (string) $returned, 3);

                if (Decimal::compare($quantity, $remaining, 3) > 0) {
                    throw new DomainException('مقدار برگشت فروش از مقدار قابل برگشت ردیف بیشتر است.');
                }

                $isFull = Decimal::compare($quantity, $originalItem->quantity, 3) === 0;
                $gross = Decimal::multiply($quantity, $originalItem->unit_price, 2);
                $discount = $isFull
                    ? Decimal::normalize($originalItem->discount_amount, 2)
                    : Decimal::divide(Decimal::multiply($originalItem->discount_amount, $quantity, 4), $originalItem->quantity, 2);
                $taxable = Decimal::subtract($gross, $discount, 2);
                $taxAmount = $isFull
                    ? Decimal::normalize($originalItem->tax_amount, 2)
                    : Decimal::divide(Decimal::multiply($originalItem->tax_amount, $quantity, 4), $originalItem->quantity, 2);
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
    }

    public function confirm(Invoice $returnInvoice, int $warehouseId, User $approver): Invoice
    {
        return DB::transaction(function () use ($returnInvoice, $warehouseId, $approver): Invoice {
            $return = Invoice::query()
                ->with(['items', 'customer.party'])
                ->lockForUpdate()
                ->findOrFail($returnInvoice->id);

            if ($return->type !== InvoiceType::SalesReturn || $return->status !== InvoiceStatus::Draft) {
                throw new DomainException('فقط برگشت فروش پیش‌نویس قابل تأیید است.');
            }

            if (! $return->customer?->party?->is_active
                || (int) $return->customer->party->store_id !== (int) $return->store_id) {
                throw new DomainException('مشتری برگشت فروش معتبر نیست.');
            }

            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            if ((int) $warehouse->store_id !== (int) $return->store_id
                || (int) $warehouse->branch_id !== (int) $return->branch_id) {
                throw new DomainException('انبار برگشت فروش باید متعلق به همان فروشگاه و شعبه باشد.');
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

            foreach ($return->items as $item) {
                $original = $item->parentItem()->firstOrFail();
                $isFull = Decimal::compare($item->quantity, $original->quantity, 3) === 0;
                $itemCost = $isFull
                    ? Decimal::normalize($original->total_cost_snapshot, 2)
                    : Decimal::divide(
                        Decimal::multiply($original->total_cost_snapshot, $item->quantity, 4),
                        $original->quantity,
                        2,
                    );
                $unitCost = Decimal::divide($itemCost, $item->quantity, 4);

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
                $taxTotal = Decimal::add($taxTotal, $item->tax_amount, 2);
            }

            $taxable = Decimal::subtract($return->total, $taxTotal, 2);
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
                    'credit' => $return->total,
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

            $return->forceFill(['status' => InvoiceStatus::Confirmed])->save();

            return $return->refresh()->load(['items', 'parentInvoice']);
        });
    }
}
