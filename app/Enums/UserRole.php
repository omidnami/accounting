<?php

namespace App\Enums;

enum UserRole: string
{
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Cashier = 'cashier';
    case Warehouse = 'warehouse';
    case Viewer = 'viewer';

    public function canApproveJournalEntries(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canViewFinancialReports(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManageParties(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }
}
