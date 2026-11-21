<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // behind nginx / Cloudflare / a load balancer: TRUSTED_PROXIES=* (or a comma separated list of IPs) so the real client IP and https are seen
        if ($p = env('TRUSTED_PROXIES')) $middleware->trustProxies(at: $p === '*' ? '*' : array_map('trim', explode(',', $p)));
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class, 'forget.snapshot' => \App\Http\Middleware\ForgetSnapshot::class]);
        $middleware->redirectGuestsTo(fn () => '/login.html');   // a signed-out browser visit lands on the login page (the API itself answers 401 JSON)
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // business-rule errors (App\Support\PlanException) are shown to the user as-is
        $exceptions->render(fn (\App\Support\PlanException $e) => response()->json(["error" => $e->getMessage()], 422));
        $exceptions->render(fn (\Illuminate\Auth\AuthenticationException $e, $request) => $request->is('api/*') ? response()->json(['error' => 'Please sign in again.'], 401) : null);
        // API clients always get JSON (never an HTML error page) — and no stack traces in production
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
