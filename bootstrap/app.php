<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureClientPlatform;
use App\Http\Middleware\EnsureDeviceIsBound;
use App\Http\Middleware\EnsurePremiseLocation;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->api(append: [StartSession::class]);

        $middleware->alias([
            'active.session' => EnsureActiveSession::class,
            'device.bound' => EnsureDeviceIsBound::class,
            'platform' => EnsureClientPlatform::class,
            'role' => EnsureUserHasRole::class,
            'premise.location' => EnsurePremiseLocation::class,
        ]);

        // Es una API: un invitado recibe 401 en JSON, no una redirección al login.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Errores esperados que ya se responden como JSON: no son fallos que reportar.
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
