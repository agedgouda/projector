<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationPdfBrandingController extends Controller
{
    /** @var array<string, string> */
    private const COLLECTIONS = [
        'header' => 'pdf_header',
        'footer' => 'pdf_footer',
    ];

    public function store(Request $request, Organization $organization, string $type): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $organization);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $organization->addMediaFromRequest('logo')->toMediaCollection(self::COLLECTIONS[$type]);

        return back();
    }

    public function destroy(Organization $organization, string $type): RedirectResponse
    {
        setPermissionsTeamId($organization->id);
        Gate::authorize('update', $organization);

        $organization->clearMediaCollection(self::COLLECTIONS[$type]);

        return back();
    }
}
