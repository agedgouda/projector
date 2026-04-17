<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSetupController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->organizations->isNotEmpty()) {
            return redirect()->route('organizations.index');
        }

        return Inertia::render('auth/OrganizationSetup');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $slug = Str::slug($value);
                    $normalized = Organization::normalize($value);

                    $conflict = Organization::where(function ($query) use ($slug, $normalized) {
                        $query->where('slug', $slug)
                            ->orWhere('normalized_name', $normalized);
                    })->first(['name']);

                    if ($conflict) {
                        $fail("An organization with the name '{$conflict->name}' already exists.");
                    }
                },
            ],
        ]);

        $org = Organization::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'normalized_name' => Organization::normalize($validated['name']),
        ]);

        $org->users()->attach($request->user()->id, ['role' => 'org-admin']);

        return redirect()->route('organizations.index', ['org' => $org->id])
            ->with('success', 'Your organization has been created.');
    }
}
