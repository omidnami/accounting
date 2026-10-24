<?php

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property int $version
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'version'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            $versioned = ['name', 'email', 'password', 'role', 'email_verified_at'];

            if (array_intersect($versioned, array_keys($user->getDirty())) !== []
                && ! $user->isDirty('version')) {
                $user->version = (int) $user->getOriginal('version') + 1;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'version' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'created_by');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'created_by');
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'created_by');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'created_by');
    }

    /**
     * @return HasMany<Check, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class, 'created_by');
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'created_by');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function canApproveJournalEntries(): bool
    {
        return $this->role->canApproveJournalEntries();
    }

    public function canViewFinancialReports(): bool
    {
        return $this->role->canViewFinancialReports();
    }

    public function canManageParties(): bool
    {
        return $this->role->canManageParties();
    }

    public function canManageProducts(): bool
    {
        return $this->role->canManageProducts();
    }

    public function canViewInventoryReports(): bool
    {
        return $this->role->canViewInventoryReports();
    }

    public function canViewInventoryCosts(): bool
    {
        return $this->role->canViewInventoryCosts();
    }

    public function canManageStockCounts(): bool
    {
        return $this->role->canManageStockCounts();
    }

    public function canPostStockCounts(): bool
    {
        return $this->role->canPostStockCounts();
    }

    public function canTransferInventory(): bool
    {
        return $this->role->canTransferInventory();
    }

    public function canManageSales(): bool
    {
        return $this->role->canManageSales();
    }

    public function canConfirmSales(): bool
    {
        return $this->role->canConfirmSales();
    }

    public function canManageReceipts(): bool
    {
        return $this->role->canManageReceipts();
    }

    public function canManagePurchases(): bool
    {
        return $this->role->canManagePurchases();
    }

    public function canManagePayments(): bool
    {
        return $this->role->canManagePayments();
    }

    public function canManageChecks(): bool
    {
        return $this->role->canManageChecks();
    }

    public function canManageTreasury(): bool
    {
        return $this->role->canManageTreasury();
    }

    public function canManageUsers(): bool
    {
        return $this->role->canManageUsers();
    }

    public function canManageFiscalYears(): bool
    {
        return $this->role->canManageFiscalYears();
    }

    public function canCloseAccountingPeriods(): bool
    {
        return $this->role->canCloseAccountingPeriods();
    }

    public function canReopenAccountingPeriods(): bool
    {
        return $this->role->canReopenAccountingPeriods();
    }

    public function canCloseFiscalYears(): bool
    {
        return $this->role->canCloseFiscalYears();
    }

    public function canReopenFiscalYears(): bool
    {
        return $this->role->canReopenFiscalYears();
    }

    public function canViewAuditLogs(): bool
    {
        return $this->role->canViewAuditLogs();
    }
}
