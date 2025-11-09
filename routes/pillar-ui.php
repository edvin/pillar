<?php

use Illuminate\Support\Facades\Route;
use Pillar\Http\Controllers\UI\AggregateController;
use Pillar\Http\Controllers\UI\DashboardController;


Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::get('/aggregate', [AggregateController::class, 'show'])->name('aggregate.show');

//Route::get('api/event/{sequence}', [AggregateController::class, 'event'])->name('event.show');
//Route::get('api/aggregate/state', [AggregateController::class, 'state'])->name('aggregate.state');

// --- API endpoints (JSON only) -------------------------------------------
// New canonical paths; keep legacy routes above for BC.
Route::prefix('api')->as('api.')->group(function () {
    Route::get('/recent', [DashboardController::class, 'recent'])->name('recent');
    Route::get('/aggregate/events', [AggregateController::class, 'events'])->name('aggregate.events');
    // Future additions:
    // Route::get('/event/{sequence}', [AggregateController::class, 'event'])->name('event.show');
    // Route::get('/aggregate/state', [AggregateController::class, 'state'])->name('aggregate.state');
});