<?php

namespace App\Services\Purchasing;

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
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
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
    public function createDraft(
        Invoice $purchaseInvoice,
        array $items,
        string $returnDate,
        ?int $createdBy = null,
        ?string $notes = null,
    ): Invoice {
        if ($items === []) {
            throw new DomainException('برگشت خرید باید حداقل یک قلم داشته باشد.');
        }

        return DB::transaction(function () use (
            $purchaseInvoice,
            $items,
            $returnDate,
            $createdBy,
            $notes,
        ): Invoice {
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

                $returnedPreviously = InvoiceItem::query()
                    ->where('parent_invoice_item_id', $originalItem->id)
                    ->whereHas('invoice', function ($query): void {
                        $query->where('type', InvoiceType::PurchaseReturn)
                            ->where('status', InvoiceStatus::Confirmed);
                    })
                    ->sum('quantity');
                $remaining = Decimal::subtract(
                    $originalItem->quantity,
                    (string) $returnedPreviously,
                    Decimal::QUANTITY_SCALE,
                );

                if (Decimal::compare($quantity, $remaining, Decimal::QUANTITY_SCALE) > 0) {
                    throw new DomainException('مقدار برگشت از مقدار قابل برگشت ردیف بیشتر است.');
                }

                $isFullLine = Decimal::compare($quantity, $originalItem->quantity, Decimal::QUANTITY_SCALE) === 0;
                $gross = Decimal::multiply($quantity, $originalItem->unit_price, Decimal::MONEY_SCALE);
                $discount = $isFullLine
                    ? Decimal::normalize($originalItem->discount_amount, Decimal::MONEY_SCALE)
                    : Decimal::divide(
                        Decimal::multiply($originalItem->discount_amount, $quantity, 4),
                        $originalItem->quantity,
                        Decimal::MONEY_SCALE,
                    );
                $taxable = Decimal::subtract($gross, $discount, Decimal::MONEY_SCALE);
                $taxAmount = $isFullLine
                    ? Decimal::normalize($originalItem->tax_amount, Decimal::MONEY_SCALE)
                    : Decimal::divide(
                        Decimal::multiply($originalItem->tax_amount, $quantity, 4),
                        $originalItem->quantity,
                        Decimal::MONEY_SCALE,
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
    }

    public function confirm(Invoice $returnInvoice, int $warehouseId, User $approver): Invoice
    {
        return DB::transaction(function () use ($returnInvoice, $warehouseId, $approver): Invoice {
            $return = Invoice::query()
                ->with(['items', 'parentInvoice', 'supplier.party'])
                ->lockForUpdate()
                ->findOrFail($returnInvoice->id);

            if ($return->type !== InvoiceType::PurchaseReturn
                || $return->status !== InvoiceStatus::Draft) {
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

            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            if ((int) $warehouse->store_id !== (int) $return->store_id
                || (int) $warehouse->branch_id !== (int) $return->branch_id) {
                throw new DomainException('انبار باید متعلق به فروشگاه و شعبه سند برگشت باشد.');
            }

            $inventoryCost = '0.00';
            $taxTotal = '0.00';

            foreach ($return->items as $item) {
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
                $taxTotal = Decimal::add($taxTotal, $item->tax_amount, Decimal::MONEY_SCALE);
            }

            $difference = Decimal::subtract(
                Decimal::subtract($return->total, $inventoryCost, Decimal::MONEY_SCALE),
                $taxTotal,
                Decimal::MONEY_SCALE,
            );
            $lines = [
                [
                    'account_id' => $this->settings->defaultAccountId('accounts_payable', $return->store_id),
                    'party_id' => $supplier->party_id,
                    'debit' => $return->total,
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

            $return->forceFill(['status' => InvoiceStatus::Confirmed])->save();

            return $return->refresh()->load(['items', 'parentInvoice']);
        });
    }
}
