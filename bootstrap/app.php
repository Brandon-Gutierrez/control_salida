<?php

use App\Http\Middleware\CheckActiveSession;
use App\Http\Middleware\CheckAuthorization;
use App\Http\Middleware\CheckDeviceId;
use App\Http\Middleware\CheckPremiseLocation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware ->statefulApi();
        $middleware ->api(append: [StartSession::class,]);

        $middleware->alias([
            'check.authorization' => CheckAuthorization::class,
            'check.deviceid' => CheckDeviceId::class,
            'check.active.session' => CheckActiveSession::class,
            'check.premise.location' => CheckPremiseLocation::class,
        ]);
        
        $middleware->redirectGuestsTo(function ($request) {
            return null;            
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, $input){
        return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
