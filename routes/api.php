<?php

use App\Http\Controllers\Api\PrintAgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('print-agent')->group(function (): void {
    Route::post('claim', [PrintAgentController::class, 'claim']);
    Route::post('jobs/{job}/complete', [PrintAgentController::class, 'complete']);
});
