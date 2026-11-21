<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route-level role check (defence in depth: the controllers check again). Usage: ->middleware('role:admin') */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $u = $request->user();
        if (! $u || $u->role !== $role) return response()->json(['error' => 'Not allowed.'], 403);
        return $next($request);
    }
}
