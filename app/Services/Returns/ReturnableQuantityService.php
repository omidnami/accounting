<?php

namespace App\Services\Returns;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\InvoiceItem;
use App\Support\Decimal;
use DomainException;

class ReturnableQuantityService
{
    /**
     * @return array{
     *     remaining_quantity: string, returned_quantity: string,
     *     returned_discount: string, returned_tax: string, returned_cost: string
     * }
     */
    public function stateFor(
        InvoiceItem $originalItem,
        InvoiceType $returnType,
        bool $lockForUpdate = false,
    ): array {
        $totals = $this->confirmedTotalsFor(
            $originalItem,
            $returnType,
            $lockForUpdate,
        );
        $remaining = Decimal::subtract(
            $originalItem->quantity,
            $totals['quantity'],
            Decimal::QUANTITY_SCALE,
        );

        if (Decimal::compare($remaining, '0', Decimal::QUANTITY_SCALE) < 0) {
            throw new DomainException('مقدار برگشت‌شده از مقدار اصلی ردیف بیشتر است.');
        }

        return [
            'remaining_quantity' => $remaining,
            'returned_quantity' => $totals['quantity'],
            'returned_discount' => $totals['discount'],
            'returned_tax' => $totals['tax'],
            'returned_cost' => $totals['cost'],
        ];
    }

    public function remainingFor(
        InvoiceItem $originalItem,
        InvoiceType $returnType,
    ): string {
        return $this->stateFor($originalItem, $returnType)['remaining_quantity'];
    }

    public function allocateMoney(
        string|int|float $originalAmount,
        string|int|float $alreadyAllocated,
        string|int|float $requestedQuantity,
        string|int|float $originalQuantity,
        bool $isFinal,
    ): string {
        if ($isFinal) {
            $remaining = Decimal::subtract(
                $originalAmount,
                $alreadyAllocated,
                Decimal::MONEY_SCALE,
            );

            return Decimal::compare($remaining, '0', Decimal::MONEY_SCALE) > 0
                ? $remaining
                : '0.00';
        }

        return Decimal::divide(
            Decimal::multiply(
                $originalAmount,
                $requestedQuantity,
                Decimal::UNIT_COST_SCALE,
            ),
            $originalQuantity,
            Decimal::MONEY_SCALE,
        );
    }

    /**
     * @return array{quantity: string, discount: string, tax: string, cost: string}
     */
    private function confirmedTotalsFor(
        InvoiceItem $originalItem,
        InvoiceType $returnType,
        bool $lockForUpdate = false,
    ): array {
        if (! in_array($returnType, [
            InvoiceType::SalesReturn,
            InvoiceType::PurchaseReturn,
        ], true)) {
            throw new DomainException('نوع برگشت برای محاسبه مقدار قابل برگشت معتبر نیست.');
        }

        $query = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.parent_invoice_item_id', $originalItem->id)
            ->where('invoices.type', $returnType)
            ->where('invoices.status', InvoiceStatus::Confirmed)
            ->orderBy('invoice_items.id');
        $rows = $lockForUpdate
            ? $query->lockForUpdate()->get([
                'invoice_items.quantity',
                'invoice_items.discount_amount',
                'invoice_items.tax_amount',
                'invoice_items.total_cost_snapshot',
            ])
            : $query->get([
                'invoice_items.quantity',
                'invoice_items.discount_amount',
                'invoice_items.tax_amount',
                'invoice_items.total_cost_snapshot',
            ]);
        $quantity = '0.000';
        $discount = '0.00';
        $tax = '0.00';
        $cost = '0.00';

        foreach ($rows as $row) {
            $quantity = Decimal::add($quantity, $row->quantity, 3);
            $discount = Decimal::add($discount, $row->discount_amount, 2);
            $tax = Decimal::add($tax, $row->tax_amount, 2);
            $cost = Decimal::add(
                $cost,
                $row->total_cost_snapshot ?? 0,
                Decimal::MONEY_SCALE,
            );
        }

        return [
            'quantity' => $quantity,
            'discount' => $discount,
            'tax' => $tax,
            'cost' => $cost,
        ];
    }
}
