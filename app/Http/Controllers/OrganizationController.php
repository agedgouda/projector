<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationRequest;
use App\Http\Requests\UpdateOrganizationTierRequest;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Fetch available organizations using the 'accessibleBy' scope
        $organizations = Organization::accessibleBy($user)->get();

        // 2. Handle empty states (Super-admin vs Member)
        if ($organizations->isEmpty()) {
            return $user->hasRole('super-admin')
                ? Inertia::render('Organizations/Create')
                : Inertia::render('Organizations/AccessPending');
        }

        // 3. Resolve the Current Org (Query > Cookie > First Available)
        $orgId = $request->query('org', $request->cookie('last_org_id'));
        $currentOrg = $organizations->firstWhere('id', $orgId) ?? $organizations->first();
        $currentOrg->load('media');

        // 4. Set the Spatie context BEFORE checking the Policy
        setPermissionsTeamId($currentOrg->id);

        // 5. Verify the user has permission to view this specific organization
        Gate::authorize('view', $currentOrg);

        // 6. Get members formatted for Inertia (using your existing macro/method)
        $usersRecord = User::query()
            ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $currentOrg->id))
            ->get()
            ->forInertia();

        $members = $usersRecord[$currentOrg->name] ?? [];

        // 7. Get Addable Users using the new 'addableToOrganization' scope
        $pivotRole = $currentOrg->users()->where('users.id', $user->id)->first()?->pivot?->role;
        $canManageUsers = $user->hasRole('super-admin') || $pivotRole === 'org-admin';

        $addableUsers = $canManageUsers
            ? User::addableToOrganization($currentOrg)->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'roles' => [],
            ])
            : collect();

        $currentOrgData = array_merge($currentOrg->makeHidden(['llm_config', 'vector_config'])->toArray(), [
            'llm_config_form' => $currentOrg->llmConfigForForm(),
            'vector_config_form' => $currentOrg->vectorConfigForForm(),
            'users' => $members,
            'can' => [
                'update' => $user->can('update', $currentOrg),
                'manage_users' => $user->can('manageUsers', $currentOrg),
                'delete' => $user->can('delete', $currentOrg),
            ],
        ]);

        $invitations = OrganizationInvitation::where('organization_id', $currentOrg->id)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get(['id', 'email', 'token', 'expires_at']);

        $clients = $currentOrg->clients()->with(['projects.media', 'media'])->get()
            ->map(fn (Client $c) => array_merge($c->toArray(), [
                'logo_url' => $c->logo_url,
                'projects' => $c->projects->map(fn ($p) => array_merge($p->toArray(), ['logo_url' => $p->logo_url]))->all(),
            ]));
        $projectTypes = ProjectType::all();

        $usageByProject = AiUsageLog::query()
            ->where('organization_id', $currentOrg->id)
            ->where('type', 'llm')
            ->selectRaw('project_id, client_id, COUNT(*) as documents_processed, SUM(cost_usd) as cost_usd')
            ->groupBy('project_id', 'client_id')
            ->get();

        $usageTotals = [
            'documents_processed' => (int) $usageByProject->sum('documents_processed'),
            'cost_usd' => (float) $usageByProject->sum('cost_usd'),
        ];

        $usageByClient = $usageByProject->groupBy('client_id')->map(fn ($rows) => [ // @phpstan-ignore return.type
            'documents_processed' => (int) $rows->sum('documents_processed'),
            'cost_usd' => (float) $rows->sum('cost_usd'),
            'projects' => $rows->map(fn ($row) => [
                'project_id' => $row->project_id,
                'documents_processed' => (int) $row->documents_processed,
                'cost_usd' => (float) $row->cost_usd,
            ])->values(),
        ]);

        cookie()->queue(cookie()->forever('last_org_id', (string) $currentOrg->id));

        return Inertia::render('Organizations/Show', [
            'organizations' => $organizations,
            'currentOrg' => array_merge($currentOrg->makeHidden(['llm_config', 'vector_config', 'meeting_config'])->toArray(), [
                'logo_url' => $currentOrg->logo_url,
                'users' => $members,
                'llm_config_form' => $currentOrg->llmConfigForForm(),
                'vector_config_form' => $currentOrg->vectorConfigForForm(),
                'meeting_config_form' => $currentOrg->meetingConfigForForm(),
                'can' => [
                    'update' => $user->can('update', $currentOrg),
                    'manage_users' => $user->can('manageUsers', $currentOrg),
                    'delete' => $user->can('delete', $currentOrg),
                ],
            ]),
            'users' => $addableUsers,
            'allRoles' => ['org-admin', 'project-lead', 'team-member'],
            'invitations' => $invitations,
            'clients' => $clients,
            'projectTypes' => $projectTypes,
            'usageTotals' => $usageTotals,
            'usageByClient' => $usageByClient,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Typically handled via a modal, but if needed:
        return Inertia::render('Organizations/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(OrganizationRequest $request)
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $org = Organization::create($request->validated());

        setPermissionsTeamId($org->id);

        if ($request->hasFile('logo')) {
            $org->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        return redirect()->route('organizations.index', ['org' => $org->id])
            ->with('success', 'Organization created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Organization $organization)
    {
        // Redirect to index with the org parameter to keep logic centralized
        return redirect()->route('organizations.index', ['org' => $organization->id]);
    }

    public function edit(Organization $organization)
    {
        Gate::authorize('update', $organization);

        $organization->load(['users', 'media']);

        return inertia('Organizations/Edit', [
            'organization' => array_merge(
                $organization->makeHidden(['llm_config', 'vector_config', 'meeting_config'])->toArray(),
                [
                    'logo_url' => $organization->logo_url,
                    'llm_config_form' => $organization->llmConfigForForm(),
                    'vector_config_form' => $organization->vectorConfigForForm(),
                    'meeting_config_form' => $organization->meetingConfigForForm(),
                ]
            ),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(OrganizationRequest $request, Organization $organization)
    {
        // 1. Only fill the flat, basic attributes first
        // We exclude the config arrays from the initial fill to let the model method handle them
        $organization->fill($request->safe()->except(['llm_config', 'vector_config', 'meeting_config']));

        // 2. Explicitly pass the input arrays to the merge logic
        $organization->fillConfiguration(
            'llm',
            $request->input('llm_driver'),
            $request->input('llm_config', [])
        );

        $organization->fillConfiguration(
            'vector',
            $request->input('vector_driver'),
            $request->input('vector_config', [])
        );

        $organization->fillMeetingConfiguration(
            $request->input('meeting_provider'),
            $request->input('meeting_config', [])
        );

        // 3. Save everything
        $organization->save();

        return back()->with('success', 'Organization updated successfully.');
    }

    /**
     * Add a user to the specified organization. Super-admins only.
     */
    public function addUser(Request $request, Organization $organization): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('manageUsers', $organization);

        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::findOrFail($request->user_id);

        if ($organization->users()->where('user_id', $user->id)->exists()) {
            return back()->withErrors(['user_id' => 'User is already a member of this organization.']);
        }

        if ($block = \App\Services\MembershipGuard::check($organization, 'users')) {
            return $block;
        }

        $organization->users()->attach($user->id, ['role' => 'team-member']);

        return back()->with('success', 'User added to organization.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Organization $organization)
    {
        // Set context to ensure proper policy evaluation
        setPermissionsTeamId($organization->id);

        // This checks OrganizationPolicy@delete (currently restricted to Super Admin)
        Gate::authorize('delete', $organization);

        $organization->delete();

        return redirect()->route('organizations.index')->with('success', 'Organization removed.');
    }

    public function adminIndex(): \Inertia\Response
    {
        $orgs = Organization::withCount(['users', 'clients', 'invitations'])
            ->get()
            ->map(function (Organization $org) {
                $projectCount = Project::whereHas('client', fn ($q) => $q->where('organization_id', $org->id))->count();
                $aiUsageThisMonth = AiUsageLog::where('organization_id', $org->id)
                    ->where('type', 'llm')
                    ->whereYear('created_at', Carbon::now()->year)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->count();

                $limits = $org->tierLimits();

                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'membership_tier' => $org->membership_tier,
                    'tier_label' => $org->tierLabel(),
                    'users_count' => $org->users_count + $org->invitations_count,
                    'users_denominator' => $org->planned_user_count ?? $limits['users'],
                    'clients_count' => $org->clients_count,
                    'projects_count' => $projectCount,
                    'ai_docs_this_month' => $aiUsageThisMonth,
                    'ai_docs_limit' => $limits['ai_docs_per_month'],
                    'created_at' => $org->created_at->format('m/d/Y'),
                ];
            });

        return Inertia::render('Admin/Organizations', [
            'organizations' => $orgs,
        ]);
    }

    public function updateTier(UpdateOrganizationTierRequest $request, Organization $organization): \Illuminate\Http\RedirectResponse
    {
        $organization->update(['membership_tier' => $request->membership_tier]);

        return back()->with('success', 'Tier updated.');
    }
}
