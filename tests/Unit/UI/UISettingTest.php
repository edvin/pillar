<?php

use Pillar\Support\UI\UISettings;

it('normalizes skip_auth_in when provided as an array', function () {
    // Includes spaces and empties to ensure trimming + filtering
    $settings = new UISettings(
        enabled: true,
        path: 'pillar',
        guard: 'web',
        skipAuthIn: [' local ', '', 'testing', '  '],
        pageSize: 100,
        recentLimit: 20,
    );

    expect($settings->skipAuthIn)->toBe(['local', 'testing']);
});