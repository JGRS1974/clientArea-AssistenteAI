<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Configurar rate limiters
            RateLimiter::for('api', function (Request $request) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)
                    ->by($request->user()?->id ?: $request->ip());
            });

            RateLimiter::for('auth', function (Request $request) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)
                    ->by($request->ip());
            });
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
        // CORS nativo do Laravel 12
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Middleware padrão para API
        $middleware->api([
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
