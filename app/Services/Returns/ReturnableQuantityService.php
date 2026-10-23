<?php

namespace App\Services\Returns;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\InvoiceItem;
use App\Support\Decimal;
use DomainException;

class ReturnableQuantityService
{
    public function remainingFor(
        InvoiceItem $originalItem,
        InvoiceType $returnType,
    ): string {
        if (! in_array($returnType, [
            InvoiceType::SalesReturn,
            InvoiceType::PurchaseReturn,
        ], true)) {
            throw new DomainException('نوع برگشت برای محاسبه مقدار قابل برگشت معتبر نیست.');
        }

        $returned = InvoiceItem::query()
            ->where('parent_invoice_item_id', $originalItem->id)
            ->whereHas('invoice', fn ($query) => $query
                ->where('type', $returnType)
                ->where('status', InvoiceStatus::Confirmed))
            ->sum('quantity');
        $remaining = Decimal::subtract(
            $originalItem->quantity,
            (string) $returned,
            Decimal::QUANTITY_SCALE,
        );

        if (Decimal::compare($remaining, '0', Decimal::QUANTITY_SCALE) < 0) {
            throw new DomainException('مقدار برگشت‌شده از مقدار اصلی ردیف بیشتر است.');
        }

        return $remaining;
    }
}
