<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generations')
        ->name('generations.store');

    Route::post('credits', [CreditPurchaseController::class, 'store'])
        ->middleware('throttle:10,1,credits') // the prefix keeps its counter apart from other per-user throttles
        ->name('credits.store');

    Route::get('admin/report', AdminReportController::class)
        ->middleware('can:viewSpendReport')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
