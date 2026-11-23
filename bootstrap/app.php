<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', // website enquiry endpoint
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Meta webhooks: no session/cookies/CSRF (signature-verified instead).
        then: fn () => \Illuminate\Support\Facades\Route::group([], base_path('routes/webhooks.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
        $middleware->redirectUsersTo('/inbox');
        // Behind Render's (or any) HTTPS proxy: trust X-Forwarded-* so URLs, redirects and secure cookies use https.
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: ['webhooks/*']); // belt and braces; webhooks aren't in the web group
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Cookie sessions are limited to ~4 KB: never flash a long reply body back on validation errors.
        $exceptions->dontFlash(['body']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
