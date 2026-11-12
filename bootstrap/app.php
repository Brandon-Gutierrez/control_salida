<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureClientPlatform;
use App\Http\Middleware\EnsureDeviceIsBound;
use App\Http\Middleware\EnsureLocationProof;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // La API solo se alcanza a través del gateway (nginx) y del proxy de la empresa:
        // se confía en sus cabeceras X-Forwarded-* para conocer la IP y el esquema reales.
        $middleware->trustProxies(at: '*');

        $middleware->statefulApi();
        $middleware->api(append: [StartSession::class]);

        $middleware->alias([
            'active.session' => EnsureActiveSession::class,
            'device.bound' => EnsureDeviceIsBound::class,
            'platform' => EnsureClientPlatform::class,
            'role' => EnsureUserHasRole::class,
            'location.proof' => EnsureLocationProof::class,
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
