<?php

namespace App\Http\Controllers;

use App\Models\{User, Organization};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Str;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole('super-admin')) {
            $organizations = Organization::all();
        } else {
            $organizations = $user->organizations()->get();
        }

        if ($organizations->isEmpty()) {
            return Inertia::render('Organizations/AccessPending');
        }

        // 1. Resolve the current Org (URL > Cookie > First)
        $orgId = $request->query('org') ?? $request->cookie('last_org_id');
        $currentOrg = $organizations->firstWhere('id', $orgId) ?? $organizations->first();

        // 2. Set the Spatie context BEFORE checking the Policy
        setPermissionsTeamId($currentOrg->id);

        // 3. Verify the user has permission to view this specific organization
        Gate::authorize('view', $currentOrg);

        // 4. Get the users formatted via forInertia()
        $usersRecord = User::query()
            ->whereHas('organizations', fn($q) => $q->where('organizations.id', $currentOrg->id))
            ->get()
            ->forInertia();

        $members = $usersRecord[$currentOrg->name] ?? [];

        // 5. Build the data array with frontend permissions (can)
        $currentOrgData = array_merge($currentOrg->toArray(), [
            'users' => $members,
            'can' => [
                'update' => $user->can('update', $currentOrg),
                'manage_users' => $user->can('manageUsers', $currentOrg),
                'delete' => $user->can('delete', $currentOrg),
            ]
        ]);

        return Inertia::render('Organizations/Show', [
            'organizations' => $organizations,
            'currentOrg'   => $currentOrgData,
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
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);

        $normalized = Organization::normalize($request->name);

        if (Organization::where('normalized_name', $normalized)->exists()) {
            return back()->withErrors([
                'name' => "An organization with a similar name already exists."
            ]);
        }

        $org = Organization::create([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'normalized_name' => $normalized,
        ]);

        $user = $request->user();

        // Super-admins do not need to be in the pivot table to see or manage the org
        if (!$user->hasRole('super-admin')) {
            $user->organizations()->attach($org->id);
        }

        setPermissionsTeamId($org->id);
        $user->assignRole('org-admin');

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

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Organization $organization)
    {
        // Set the team context before checking permissions
        setPermissionsTeamId($organization->id);

        // This checks OrganizationPolicy@update via the isOrgAdmin trait method
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $organization->update([
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'normalized_name' => Organization::normalize($validated['name']),
        ]);

        return back()->with('success', 'Organization updated.');
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
