<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Safe-by-default response headers for every API response. API answers are never cached. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");   // an API answer is data, never a page
        $h->set('Cross-Origin-Resource-Policy', 'same-site');
        if ($request->is('api/*')) $h->set('Cache-Control', 'no-store, private');
        if ($request->isSecure()) $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        return $response;
    }
}
