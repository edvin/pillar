<?php

use Illuminate\Contracts\Auth\Factory;
use Illuminate\Support\Facades\Route;
use Illuminate\Contracts\Auth\Authenticatable;
use Pillar\Http\Middleware\AuthorizePillarUI;
use Pillar\Security\HasPillarAccess;
use Pillar\Security\PillarUser;
use Pillar\Support\UI\UISettings;

// Minimal route protected by our middleware
beforeEach(function () {
    config()->set('pillar.ui.enabled', true);
    config()->set('pillar.ui.skip_auth_in', '');    // do not skip in testing
    config()->set('pillar.ui.guard', 'web');

    // ensure alias exists
    app('router')->aliasMiddleware('pillar.ui', AuthorizePillarUI::class);

    Route::middleware('pillar.ui')->get('/__pillar_test', fn () => 'ok');
});

class __InjectRequestUserMiddleware
{
    public static $user;

    public function handle($request, \Closure $next)
    {
        $request->setUserResolver(fn () => self::$user);
        return $next($request);
    }
}

it('denies anonymous users', function () {
    $this->get('/__pillar_test')->assertStatus(401);
});

it('denies authenticated users without PillarUser', function () {
    // Generic Authenticatable user without PillarUser interface
    $user = new class implements Authenticatable {
        use \Illuminate\Auth\Authenticatable;
    };

    $this->actingAs($user, 'web')->get('/__pillar_test')->assertStatus(403);
});

it('allows authenticated PillarUser (via HasPillarAccess)', function () {
    $user = new class implements Authenticatable, PillarUser {
        use \Illuminate\Auth\Authenticatable;
        use HasPillarAccess;
    };

    $this->actingAs($user, 'web')->get('/__pillar_test')->assertOk();
});

it('skips auth entirely when environment is whitelisted', function () {
    config()->set('pillar.ui.skip_auth_in', 'testing'); // our env

    $this->get('/__pillar_test')->assertOk();
});

it('returns 404 when UI is disabled', function () {
    // Flip the feature flag off; middleware should short-circuit to 404
    config()->set('pillar.ui.enabled', false);

    $this->get('/__pillar_test')->assertNotFound();
});

it('uses request->user() branch when no guard configured', function () {
    // Force guard to null so middleware must call $request->user()
    config()->set('pillar.ui.guard', '');

    // Ensure a fresh settings instance is resolved with the new config
    app()->forgetInstance(UISettings::class);
    app()->forgetInstance(AuthorizePillarUI::class);

    // Bind an Auth Factory that would explode if guard() were ever called
    $this->app->instance(Factory::class, new class implements Factory {
        public function guard($name = null) { throw new \RuntimeException('guard() should not be called'); }
        public function shouldUse($name) {}
        public function setDefaultDriver($name) {}
        public function getDefaultDriver() { return 'web'; }
    });

    // Provide a PillarUser via the request user resolver so request()->user() works
    $user = new class implements Authenticatable, PillarUser {
        use \Illuminate\Auth\Authenticatable;
        use HasPillarAccess;
    };

    __InjectRequestUserMiddleware::$user = $user;

    Route::get('/__pillar_test_alt', fn () => 'ok')
        ->middleware(__InjectRequestUserMiddleware::class)
        ->middleware('pillar.ui');

    // If the middleware uses request()->user(), this will succeed; if it tries guard()->user(), the test will error.
    $this->get('/__pillar_test_alt')->assertOk();
});