<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the marketing site embed the demo page in an iframe, and nobody else.
 */
class AllowDemoFraming
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $parentOrigin = config('services.demo.parent_origin');
        $ancestors = is_string($parentOrigin) && $parentOrigin !== '' ? "'self' {$parentOrigin}" : "'self'";

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors}");
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
