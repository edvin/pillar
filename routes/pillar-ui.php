<?php

use Illuminate\Support\Facades\Route;
use Pillar\Http\Controllers\UI\AggregateController;
use Pillar\Http\Controllers\UI\DashboardController;
use Pillar\Http\Controllers\UI\OutboxMonitorController;

// Admin pages
Route::get('/', [DashboardController::class, 'index'])
    ->name('index');

Route::get('/aggregate', [AggregateController::class, 'show'])
    ->name('aggregate.show');

Route::get('/outbox', [OutboxMonitorController::class, 'index'])
    ->name('outbox');

// API JSON endpoints
Route::prefix('api')->as('api.')->group(function () {
    Route::get('/recent', [DashboardController::class, 'recent'])
        ->name('recent');

    Route::get('/aggregate/events', [AggregateController::class, 'events'])
        ->name('aggregate.events');

    Route::get('/aggregate/state', [AggregateController::class, 'state'])
        ->name('aggregate.state');

    Route::get('/outbox/workers', [OutboxMonitorController::class, 'workers'])
        ->name('outbox.workers');

    Route::get('/outbox/partitions', [OutboxMonitorController::class, 'partitions'])
        ->name('outbox.partitions');

    Route::get('/outbox/messages', [OutboxMonitorController::class, 'messages'])
        ->name('outbox.messages');

    Route::get('/outbox/metrics', [OutboxMonitorController::class, 'metrics'])
        ->name('outbox.metrics');
});

