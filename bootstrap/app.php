<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // The "writes" limit (AppServiceProvider): only changes by signed-in accounts count.
            ThrottleRequests::using('writes'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Errors go to Sentry when SENTRY_LARAVEL_DSN is set (production); without it this does nothing.
        Integration::handles($exceptions);

        // A page action that hits a rate limit comes back as a form error instead of Inertia's raw
        // error modal. The sign-up and reset forms show it under the email field; elsewhere the
        // layout shows "throttle" as a notice. Other requests (the player view's fetches) keep the plain 429.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $wait = $seconds >= 120 ? ceil($seconds / 60).' minutes' : "{$seconds} seconds";
            $message = "That's a lot of requests in a short time. Wait {$wait} and try again.";

            // Only these small forms get their fields back; a whole fight or an upload isn't put in the session.
            if ($request->is('register', 'forgot-password')) {
                return back()->withInput($request->only('name', 'email'))->withErrors(['email' => $message]);
            }

            return back()->withErrors(['throttle' => $message]);
        });
    })->create();
