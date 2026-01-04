<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessClient
{
    /**
     * Handle an incoming request.
     */


public function handle(Request $request, Closure $next)
{
    $user = $request->user();

    // 1. If the user is an admin, let them through to see everything
    if ($user && $user->hasRole('admin')) {
        return $next($request);
    }

    // 2. If the user has at least one assigned client, let them through
    // The Controller will then handle filtering the specific data
    if ($user && $user->clients()->exists()) {
        return $next($request);
    }

    // 3. Otherwise, return a 404 error
    abort(404);
}
}
