<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleDashboardPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->routeIs('dashboard') && $response->getStatusCode() === 200) {
            $project = $request->attributes->get('currentProject');
            $tab = $request->attributes->get('activeTab');

            if ($project) {
                $response->withCookie(cookie()->forever('last_project_id', $project->id));
            }

            if ($tab) {
                $response->withCookie(cookie()->forever('last_active_tab', $tab));
            }
        }

        return $response;
    }
}
