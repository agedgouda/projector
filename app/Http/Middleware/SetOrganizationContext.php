<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Project;

class SetOrganizationContext
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        $organizationId = null;

        // Use ->parameter() to get the raw string/object without triggering
        // Laravel's named route lookup logic.
        $projectParam = $request->route() ? $request->route()->parameter('project') : null;

        if ($projectParam) {
            // If Model Binding already happened, it's an object. If not, it's a UUID string.
            $projectId = is_object($projectParam) ? $projectParam->id : $projectParam;

            // Query using a clean string to satisfy Postgres UUID type
            $project = \App\Models\Project::where('id', (string)$projectId)
                ->with('client')
                ->first();

            if ($project) {
                $organizationId = $project->client->organization_id;
            }
        }

        // Do the same for client
        if (!$organizationId) {
            $clientParam = $request->route() ? $request->route()->parameter('client') : null;
            if ($clientParam) {
                $clientId = is_object($clientParam) ? $clientParam->id : $clientParam;
                $organizationId = \App\Models\Client::where('id', (string)$clientId)->value('organization_id');
            }
        }

        if (!$organizationId) {
            $organizationId = $request->cookie('last_org_id')
                ?? $user->organizations()->first()?->id;
        }

        if ($organizationId) {
            setPermissionsTeamId($organizationId);
        }

        return $next($request);
    }
}
