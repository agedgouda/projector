<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    /**
     * Projects the authenticated user can record a note against. Mirrors the web
     * ProjectController@index org-resolution (?org= query param, falling back to whatever
     * the request's org context middleware already resolved).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Project::class);

        $user = auth()->user();

        if (! $user) {
            abort(401);
        }

        $orgIdParam = $request->query('org');
        $rawOrgId = is_string($orgIdParam) ? $orgIdParam : getPermissionsTeamId();
        $orgId = is_int($rawOrgId) || is_string($rawOrgId) ? (string) $rawOrgId : null;

        $projects = Project::visibleTo($user, $orgId)
            ->whereHas('client', fn ($q) => $q->where('inactive', false))
            ->with('client:id,company_name')
            ->orderBy('name')
            ->get(['id', 'name', 'client_id', 'inactive']);

        return response()->json([
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client?->company_name,
            ]),
        ]);
    }
}
