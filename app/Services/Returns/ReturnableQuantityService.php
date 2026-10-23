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
    ): array {
        $totals = $this->confirmedTotalsFor($originalItem, $returnType);
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
    ): array {
        if (! in_array($returnType, [
            InvoiceType::SalesReturn,
            InvoiceType::PurchaseReturn,
        ], true)) {
            throw new DomainException('نوع برگشت برای محاسبه مقدار قابل برگشت معتبر نیست.');
        }

        $query = InvoiceItem::query()
            ->where('parent_invoice_item_id', $originalItem->id)
            ->whereHas('invoice', fn ($query) => $query
                ->where('type', $returnType)
                ->where('status', InvoiceStatus::Confirmed));

        return [
            'quantity' => Decimal::normalize(
                (string) (clone $query)->sum('quantity'),
                Decimal::QUANTITY_SCALE,
            ),
            'discount' => Decimal::normalize(
                (string) (clone $query)->sum('discount_amount'),
                Decimal::MONEY_SCALE,
            ),
            'tax' => Decimal::normalize(
                (string) (clone $query)->sum('tax_amount'),
                Decimal::MONEY_SCALE,
            ),
            'cost' => Decimal::normalize(
                (string) (clone $query)->sum('total_cost_snapshot'),
                Decimal::MONEY_SCALE,
            ),
        ];
    }
}
