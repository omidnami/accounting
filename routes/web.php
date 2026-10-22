<?php

use App\Http\Controllers\FinancialReportsController;
use App\Http\Controllers\PartyController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('reports', FinancialReportsController::class)->name('reports.index');
    Route::resource('parties', PartyController::class)
        ->except(['show', 'destroy']);
});

require __DIR__.'/settings.php';
