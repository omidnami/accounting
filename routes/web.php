<?php

use App\Http\Controllers\CheckController;
use App\Http\Controllers\CustomerReceiptController;
use App\Http\Controllers\FinancialReportsController;
use App\Http\Controllers\InvoiceReturnController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseLookupController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SalesLookupController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\TreasuryAccountController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('reports', FinancialReportsController::class)->name('reports.index');
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
    Route::post('purchases/returns/{return}/confirm', [InvoiceReturnController::class, 'confirmPurchase'])
        ->name('purchases.returns.confirm');
    Route::delete('purchases/returns/{return}', [InvoiceReturnController::class, 'destroyPurchase'])
        ->name('purchases.returns.destroy');
});

require __DIR__.'/settings.php';
