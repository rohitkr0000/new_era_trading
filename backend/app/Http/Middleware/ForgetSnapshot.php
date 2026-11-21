<?php

namespace App\Http\Middleware;

use App\Services\Snapshot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** After any write by a member their cached dashboard snapshot is dropped, so the next page shows the change at once. */
class ForgetSnapshot
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe() && $request->user()) Snapshot::forget($request->user()->id);
        return $response;
    }
}
