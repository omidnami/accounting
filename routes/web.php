<?php

use App\Http\Controllers\CustomerReceiptController;
use App\Http\Controllers\FinancialReportsController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SalesLookupController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('reports', FinancialReportsController::class)->name('reports.index');
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
    Route::get('sales', [SalesInvoiceController::class, 'index'])->name('sales.index');
    Route::get('sales/create', [SalesInvoiceController::class, 'create'])->name('sales.create');
    Route::post('sales', [SalesInvoiceController::class, 'store'])->name('sales.store');
    Route::get('sales/{invoice}', [SalesInvoiceController::class, 'show'])->name('sales.show');
    Route::post('sales/{invoice}/confirm', [SalesInvoiceController::class, 'confirm'])
        ->name('sales.confirm');
    Route::delete('sales/{invoice}', [SalesInvoiceController::class, 'destroy'])
        ->name('sales.destroy');
});

require __DIR__.'/settings.php';
