<?php

use App\Http\Controllers\EstimateController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generations')->name('home');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('generations.store');
    Route::post('generations/estimate', EstimateController::class)
        ->middleware('throttle:120,1')
        ->name('generations.estimate');
});

require __DIR__.'/settings.php';
