<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
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
        $addableUsers = $user->hasRole('super-admin')
            ? User::addableToOrganization($currentOrg)->get()
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

        return Inertia::render('Organizations/Show', [
            'organizations' => $organizations,
            'currentOrg' => array_merge($currentOrg->toArray(), [
                'users' => $members,
                'can' => [
                    'update' => $user->can('update', $currentOrg),
                    'manage_users' => $user->can('manageUsers', $currentOrg),
                    'delete' => $user->can('delete', $currentOrg),
                ],
            ]),
            'users' => $addableUsers,
            'allRoles' => ['org-admin', 'project-lead', 'team-member'],
        ])
            ->toResponse($request)
            ->withCookie(cookie()->forever('last_org_id', (string) $currentOrg->id));
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
        // The validation & normalized check already passed.
        $org = Organization::create($request->validated());

        // Set the team context for the newly created org
        setPermissionsTeamId($org->id);

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

        return inertia('Organizations/Edit', [
            'organization' => array_merge(
                $organization->load('users')->makeHidden(['llm_config', 'vector_config', 'meeting_config'])->toArray(),
                [
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
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::findOrFail($request->user_id);

        if ($organization->users()->where('user_id', $user->id)->exists()) {
            return back()->withErrors(['user_id' => 'User is already a member of this organization.']);
        }

        $organization->users()->attach($user->id);

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
}
