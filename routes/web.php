<?php

use App\Http\Controllers\Admin\UsageReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\EstimateGenerationCostController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations/estimate', EstimateGenerationCostController::class)
        ->middleware('throttle:120,1')
        ->name('generations.estimate');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('credits.store');

    Route::get('admin/usage', UsageReportController::class)
        ->middleware('can:view-admin')
        ->name('admin.usage');
});

require __DIR__.'/settings.php';
