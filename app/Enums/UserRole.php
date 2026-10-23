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

    public function canManageProducts(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManageSales(): bool
    {
        return in_array($this, [self::Manager, self::Accountant, self::Cashier], true);
    }

    public function canConfirmSales(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManageReceipts(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManagePurchases(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManagePayments(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManageChecks(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManageTreasury(): bool
    {
        return in_array($this, [self::Manager, self::Accountant], true);
    }

    public function canManageUsers(): bool
    {
        return $this === self::Manager;
    }

    public function canViewAuditLogs(): bool
    {
        return $this === self::Manager;
    }
}
