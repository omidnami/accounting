<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SalesReturn = 'sales_return';
    case PurchaseReturn = 'purchase_return';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';

    public function supports(InventoryDirection $direction): bool
    {
        return match ($direction) {
            InventoryDirection::In => in_array($this, [
                self::Opening,
                self::Purchase,
                self::SalesReturn,
                self::Transfer,
                self::Adjustment,
            ], true),
            InventoryDirection::Out => in_array($this, [
                self::Sale,
                self::PurchaseReturn,
                self::Transfer,
                self::Adjustment,
            ], true),
        };
    }
}
