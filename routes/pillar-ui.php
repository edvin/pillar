<?php

use Illuminate\Support\Facades\Route;
use Pillar\Http\Controllers\UI\AggregateController;
use Pillar\Http\Controllers\UI\DashboardController;

// Admin pages
Route::get('/', [DashboardController::class, 'index'])
    ->name('index');

Route::get('/aggregate', [AggregateController::class, 'show'])
    ->name('aggregate.show');

// API JSON endpoints
Route::prefix('api')->as('api.')->group(function () {
    Route::get('/recent', [DashboardController::class, 'recent'])
        ->name('recent');

    Route::get('/aggregate/events', [AggregateController::class, 'events'])
        ->name('aggregate.events');

    Route::get('/aggregate/state', [AggregateController::class, 'state'])
        ->name('aggregate.state');
});