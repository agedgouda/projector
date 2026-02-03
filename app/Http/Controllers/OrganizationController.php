<?php

namespace App\Http\Controllers;

use App\Models\{User, Organization};
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Get all organizations the user belongs to
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return Inertia::render('Organizations/AccessPending');
        }

        // 2. Resolve the current Org (URL > Cookie > First)
        $orgId = $request->query('org') ?? $request->cookie('last_org_id');
        $currentOrg = $organizations->firstWhere('id', $orgId) ?? $organizations->first();

        // 3. Get the users formatted via forInertia()
        // This gives us the Record<string, User[]> where roles are flat strings
        $usersRecord = User::query()
            ->whereHas('organizations', fn($q) => $q->where('organizations.id', $currentOrg->id))
            ->get()
            ->forInertia();

        // 4. Extract the users for THIS specific organization name
        // Important: Use the organization name as the key, exactly like forInertia does
        $members = $usersRecord[$currentOrg->name] ?? [];

        // 5. Convert model to array and explicitly merge the users
        // This bypasses Eloquent's relation logic which can be finicky with custom arrays
        $currentOrgData = array_merge($currentOrg->toArray(), [
            'users' => $members
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
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);

        $normalized = Organization::normalize($request->name);

        // Check if a similar organization name already exists
        if (Organization::where('normalized_name', $normalized)->exists()) {
            return back()->withErrors([
                'name' => "An organization with a similar name already exists. Please contact your admin for an invite."
            ]);
        }

        $org = Organization::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'normalized_name' => $normalized,
        ]);

        // Attach current user as the Organization Admin
        $request->user()->organizations()->attach($org->id, ['role' => 'admin']);

        return redirect()->route('dashboard');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $user = auth()->user();

        $organizations = $user->organizations()
            ->with(['users.roles']) // Deep eager load roles for every user
            ->get();

        $initialOrgId = getPermissionsTeamId() ?? $organizations->first()?->id;

        return Inertia::render('Organizations/Show', [
            'organizations' => $organizations,
            'initialOrgId' => $initialOrgId,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Organization $organization)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Organization $organization)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Organization $organization)
    {
        //
    }
}
