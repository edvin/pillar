<?php

use Illuminate\Support\Facades\Config;

it('does not mount Pillar UI routes when disabled', function () {
    // Configure BEFORE boot so provider reads the value in boot()
    putenv('PILLAR_UI=false');
    $uniquePath = 'pillar-disabled-' . uniqid();
    putenv('PILLAR_UI_PATH=' . $uniquePath);

    $this->refreshApplication();

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    $mounted = collect($routes)->contains(function ($route) use ($uniquePath) {
        $uri = ltrim($route->uri(), '/');
        return $uri === $uniquePath || str_starts_with($uri, $uniquePath . '/');
    });

    expect($mounted)->toBeFalse();
});

it('mounts Pillar UI routes when enabled', function () {
    // Enable and use a unique path; we won't hit HTTP so no need to skip auth
    putenv('PILLAR_UI=true');
    $uniquePath = 'pillar-enabled-' . uniqid();
    putenv('PILLAR_UI_PATH=' . $uniquePath);

    $this->refreshApplication();

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    $mounted = collect($routes)->contains(function ($route) use ($uniquePath) {
        $uri = ltrim($route->uri(), '/');
        return $uri === $uniquePath || str_starts_with($uri, $uniquePath . '/');
    });

    expect($mounted)->toBeTrue();
});