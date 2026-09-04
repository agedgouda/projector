<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ImportWizardController extends Controller
{
    /**
     * Step 1 of the Import wizard: every project the signed-in user can import into, in
     * whichever organization is currently active — the same org-scoping Projects/Index uses
     * (ProjectController::index()), and the same "org-admin, project-lead, or super-admin" gate
     * the per-project import buttons are themselves hidden behind (canManageTranscripts on
     * Projects/Show.vue), so nothing offered here would 403 once the wizard reaches an actual
     * import step.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        /** @var User $user */
        $user = $request->user();

        if (! $user->hasRole('super-admin') && $user->organizations()->doesntExist()) {
            abort(404);
        }

        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();
        $orgId = is_string($orgId) ? $orgId : null;

        $projects = Project::visibleTo($user, $orgId)
            ->where('inactive', false)
            ->whereHas('client', fn ($q) => $q->where('inactive', false))
            ->with(['client.organization', 'media'])
            ->latest()
            ->get()
            ->filter(fn (Project $project) => $this->canManage($user, $project))
            ->values()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'logo_url' => $project->logo_url,
            ]);

        return Inertia::render('Import/Wizard', [
            'projects' => $projects,
            'googlePickerConfigured' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret'))
                && filled(config('services.google.api_key'))
                && filled(config('services.google.app_id')),
            'googleApiKey' => config('services.google.api_key'),
            'googleAppId' => config('services.google.app_id'),
        ]);
    }

    /**
     * Step 1/2 bootstrap data for one chosen project — the same inputs
     * Projects/Partials/ImportDocumentOptions.vue and ImportTaskListOptions.vue already receive
     * as props from Projects/Show.vue (documentTypeCatalog, the project's documents, canManage,
     * meetingProvider), fetched fresh here since the wizard never loads that page. documents is
     * trimmed to just the columns lib/documentTypes.ts's visibleDocumentTypeKeys() actually
     * reads, rather than the full record set Projects/Show.vue loads for its own page.
     */
    public function projectContext(Request $request, Project $project): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $canManage = $this->canManage($user, $project);

        abort_unless($canManage, 403);

        return response()->json([
            'canManage' => $canManage,
            'meetingProvider' => $project->client?->organization?->meeting_provider,
            'documentTypeCatalog' => $project->documentTypeCatalog()->values(),
            'documents' => $project->documents()->get(['id', 'type', 'parent_id']),
        ]);
    }

    /**
     * Same "org-admin, project-lead, or super-admin" gate DocumentImportController and
     * MeetingTranscriptController each already enforce under their own copy of this exact check
     * (authorizeManage()) before letting any import proceed — kept as its own copy here too,
     * matching how those two don't share one either.
     */
    private function canManage(User $user, Project $project): bool
    {
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($project->organization_id);

        $orgRole = $user->roleInOrganization($project->organization_id);

        return $isSuperAdmin || in_array($orgRole, ['org-admin', 'project-lead'], true);
    }
}
