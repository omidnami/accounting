<?php

use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BranchManagementController;
use App\Http\Controllers\CheckController;
use App\Http\Controllers\CustomerReceiptController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialReportsController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\InventoryCardexController;
use App\Http\Controllers\InventoryProductLookupController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\InvoiceReturnController;
use App\Http\Controllers\ManualJournalController;
use App\Http\Controllers\OperationalReportsController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseLookupController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SalesLookupController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\TreasuryAccountController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('reports', FinancialReportsController::class)->name('reports.index');
    Route::get('reports/operations', OperationalReportsController::class)
        ->name('reports.operations');
    Route::get('reports/export/financial', [ReportExportController::class, 'financial'])
        ->name('reports.export.financial');
    Route::get('reports/export/operations', [ReportExportController::class, 'operations'])
        ->name('reports.export.operations');
    Route::get('inventory/cardex/export', [ReportExportController::class, 'cardex'])
        ->name('inventory.cardex.export');
    Route::get('fiscal-years', [FiscalYearController::class, 'index'])
        ->name('fiscal-years.index');
    Route::post('fiscal-years', [FiscalYearController::class, 'store'])
        ->name('fiscal-years.store');
    Route::patch('fiscal-years/{fiscalYear}/current', [FiscalYearController::class, 'setCurrent'])
        ->name('fiscal-years.current');
    Route::patch('fiscal-years/periods/{accountingPeriod}/close', [
        FiscalYearController::class,
        'closePeriod',
    ])->name('fiscal-years.periods.close');
    Route::patch('fiscal-years/periods/{accountingPeriod}/reopen', [
        FiscalYearController::class,
        'reopenPeriod',
    ])->name('fiscal-years.periods.reopen');
    Route::patch('fiscal-years/{fiscalYear}/close', [
        FiscalYearController::class,
        'closeYear',
    ])->name('fiscal-years.close');
    Route::patch('fiscal-years/{fiscalYear}/reopen', [
        FiscalYearController::class,
        'reopenYear',
    ])->name('fiscal-years.reopen');
    Route::get('journals', [ManualJournalController::class, 'index'])
        ->name('journals.index');
    Route::get('journals/create', [ManualJournalController::class, 'create'])
        ->name('journals.create');
    Route::post('journals', [ManualJournalController::class, 'store'])
        ->name('journals.store');
    Route::get('journals/{journalEntry}', [ManualJournalController::class, 'show'])
        ->name('journals.show');
    Route::get('journals/{journalEntry}/edit', [ManualJournalController::class, 'edit'])
        ->name('journals.edit');
    Route::put('journals/{journalEntry}', [ManualJournalController::class, 'update'])
        ->name('journals.update');
    Route::post('journals/{journalEntry}/post', [ManualJournalController::class, 'post'])
        ->name('journals.post');
    Route::delete('journals/{journalEntry}', [ManualJournalController::class, 'destroy'])
        ->name('journals.destroy');
    Route::get('inventory/cardex', InventoryCardexController::class)->name('inventory.cardex');
    Route::get('inventory/lookups/products', InventoryProductLookupController::class)
        ->name('inventory.lookups.products');
    Route::post('inventory/counts/import', [StockCountController::class, 'import'])
        ->name('stock-counts.import');
    Route::resource('inventory/counts', StockCountController::class)
        ->parameters(['counts' => 'stockCount'])
        ->names('stock-counts')
        ->except('destroy');
    Route::post('inventory/counts/{stockCount}/post', [
        StockCountController::class,
        'post',
    ])->name('stock-counts.post');
    Route::delete('inventory/counts/{stockCount}', [
        StockCountController::class,
        'destroy',
    ])->name('stock-counts.destroy');
    Route::get('inventory/transfers', [InventoryTransferController::class, 'index'])
        ->name('inventory-transfers.index');
    Route::get('inventory/transfers/create', [InventoryTransferController::class, 'create'])
        ->name('inventory-transfers.create');
    Route::post('inventory/transfers', [InventoryTransferController::class, 'store'])
        ->name('inventory-transfers.store');
    Route::middleware('single-location-only')->group(function (): void {
        Route::patch('active-branch', [ActiveBranchController::class, 'update'])
            ->name('branches.switch');
        Route::resource('branches', BranchManagementController::class)
            ->except(['show', 'destroy']);
        Route::patch('branches/{branch}/default', [
            BranchManagementController::class,
            'setDefault',
        ])->name('branches.default');
        Route::patch('branches/{branch}/status', [
            BranchManagementController::class,
            'updateStatus',
        ])->name('branches.status');
    });
    Route::get('audit-logs/export', [AuditLogController::class, 'export'])
        ->name('audit-logs.export');
    Route::get('audit-logs', AuditLogController::class)->name('audit-logs.index');
    Route::resource('users', UserManagementController::class)
        ->except(['show', 'destroy']);
    Route::resource('treasury-accounts', TreasuryAccountController::class)
        ->parameters(['treasury-accounts' => 'treasuryAccount'])
        ->except(['show', 'destroy']);
    Route::patch('treasury-accounts/{treasuryAccount}/status', [
        TreasuryAccountController::class,
        'status',
    ])->name('treasury-accounts.status');
    Route::resource('parties', PartyController::class)
        ->except(['show', 'destroy']);
    Route::resource('products', ProductController::class)
        ->except(['show', 'destroy']);
    Route::get('sales/lookups/customers', [SalesLookupController::class, 'customers'])
        ->name('sales.lookups.customers');
    Route::get('sales/lookups/products', [SalesLookupController::class, 'products'])
        ->name('sales.lookups.products');
    Route::get('receipts/lookups/invoices', [CustomerReceiptController::class, 'openInvoices'])
        ->name('receipts.lookups.invoices');
    Route::get('receipts', [CustomerReceiptController::class, 'index'])->name('receipts.index');
    Route::get('receipts/create', [CustomerReceiptController::class, 'create'])->name('receipts.create');
    Route::post('receipts', [CustomerReceiptController::class, 'store'])->name('receipts.store');
    Route::get('receipts/{receipt}', [CustomerReceiptController::class, 'show'])->name('receipts.show');
    Route::get('checks', [CheckController::class, 'index'])->name('checks.index');
    Route::get('checks/create', [CheckController::class, 'create'])->name('checks.create');
    Route::post('checks', [CheckController::class, 'store'])->name('checks.store');
    Route::get('checks/{check}', [CheckController::class, 'show'])->name('checks.show');
    Route::post('checks/{check}/settle', [CheckController::class, 'settle'])->name('checks.settle');
    Route::post('checks/{check}/bounce', [CheckController::class, 'bounce'])->name('checks.bounce');
    Route::get('purchases/lookups/suppliers', [PurchaseLookupController::class, 'suppliers'])
        ->name('purchases.lookups.suppliers');
    Route::get('purchases/lookups/products', [PurchaseLookupController::class, 'products'])
        ->name('purchases.lookups.products');
    Route::get('purchases', [PurchaseInvoiceController::class, 'index'])->name('purchases.index');
    Route::get('purchases/create', [PurchaseInvoiceController::class, 'create'])->name('purchases.create');
    Route::post('purchases', [PurchaseInvoiceController::class, 'store'])->name('purchases.store');
    Route::get('purchases/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('purchases.show');
    Route::post('purchases/{invoice}/confirm', [PurchaseInvoiceController::class, 'confirm'])
        ->name('purchases.confirm');
    Route::delete('purchases/{invoice}', [PurchaseInvoiceController::class, 'destroy'])
        ->name('purchases.destroy');
    Route::get('payments/lookups/invoices', [SupplierPaymentController::class, 'openInvoices'])
        ->name('payments.lookups.invoices');
    Route::get('payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [SupplierPaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{payment}', [SupplierPaymentController::class, 'show'])->name('payments.show');
    Route::get('sales', [SalesInvoiceController::class, 'index'])->name('sales.index');
    Route::get('sales/create', [SalesInvoiceController::class, 'create'])->name('sales.create');
    Route::post('sales', [SalesInvoiceController::class, 'store'])->name('sales.store');
    Route::get('sales/{invoice}', [SalesInvoiceController::class, 'show'])->name('sales.show');
    Route::post('sales/{invoice}/confirm', [SalesInvoiceController::class, 'confirm'])
        ->name('sales.confirm');
    Route::delete('sales/{invoice}', [SalesInvoiceController::class, 'destroy'])
        ->name('sales.destroy');
    Route::get('sales/{invoice}/return', [InvoiceReturnController::class, 'createSales'])
        ->name('sales.returns.create');
    Route::post('sales/{invoice}/return', [InvoiceReturnController::class, 'storeSales'])
        ->name('sales.returns.store');
    Route::get('sales/returns/{return}', [InvoiceReturnController::class, 'showSales'])
        ->name('sales.returns.show');
    Route::get('sales/returns/{return}/edit', [InvoiceReturnController::class, 'editSales'])
        ->name('sales.returns.edit');
    Route::put('sales/returns/{return}', [InvoiceReturnController::class, 'updateSales'])
        ->name('sales.returns.update');
    Route::post('sales/returns/{return}/confirm', [InvoiceReturnController::class, 'confirmSales'])
        ->name('sales.returns.confirm');
    Route::delete('sales/returns/{return}', [InvoiceReturnController::class, 'destroySales'])
        ->name('sales.returns.destroy');
    Route::get('purchases/{invoice}/return', [InvoiceReturnController::class, 'createPurchase'])
        ->name('purchases.returns.create');
    Route::post('purchases/{invoice}/return', [InvoiceReturnController::class, 'storePurchase'])
        ->name('purchases.returns.store');
    Route::get('purchases/returns/{return}', [InvoiceReturnController::class, 'showPurchase'])
        ->name('purchases.returns.show');
    Route::get('purchases/returns/{return}/edit', [InvoiceReturnController::class, 'editPurchase'])
        ->name('purchases.returns.edit');
    Route::put('purchases/returns/{return}', [InvoiceReturnController::class, 'updatePurchase'])
        ->name('purchases.returns.update');
    Route::post('purchases/returns/{return}/confirm', [InvoiceReturnController::class, 'confirmPurchase'])
        ->name('purchases.returns.confirm');
    Route::delete('purchases/returns/{return}', [InvoiceReturnController::class, 'destroyPurchase'])
        ->name('purchases.returns.destroy');
});

require __DIR__.'/settings.php';
