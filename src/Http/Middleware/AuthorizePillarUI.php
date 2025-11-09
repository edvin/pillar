<?php

declare(strict_types=1);

namespace Pillar\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Pillar\Security\PillarUser;
use Pillar\Support\UI\UISettings;

final readonly class AuthorizePillarUI
{
    public function __construct(
        private AuthFactory $auth,
        private UISettings  $settings,
    )
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->settings->enabled) {
            abort(404);
        }

        // Open UI in selected environments (e.g. local, testing)
        if (!empty($this->settings->skipAuthIn) &&
            app()->environment(...$this->settings->skipAuthIn)) {
            return $next($request);
        }

        // Resolve user via configured guard (or default request user)
        $user = $this->settings->guard
            ? $this->auth->guard($this->settings->guard)->user()
            : $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user instanceof PillarUser && $user->canAccessPillar()) {
            return $next($request);
        }

        abort(403, 'You do not have access to Pillar UI.');
    }
}