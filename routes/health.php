<?php

use Illuminate\Support\Facades\Route;
use Pillar\Http\Controllers\HealthController;

Route::group([
    'as' => 'pillar.health.',
    // Keep this endpoint lightweight and unauthenticated by default.
    // Users can override by wrapping the route if needed.
    'middleware' => ['web'],
], function () {
    Route::get(config('pillar.health.path'), HealthController::class)
        ->name('health');
});
