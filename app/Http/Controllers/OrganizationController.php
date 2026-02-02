<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function show(Organization $organization)
    {
        //
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
