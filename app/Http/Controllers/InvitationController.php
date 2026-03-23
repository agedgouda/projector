<?php

namespace App\Http\Controllers;

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function store(Request $request, Organization $organization): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = $validated['email'];
        $inviter = $request->user();

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && $organization->users()->where('user_id', $existingUser->id)->exists()) {
            return back()->withErrors(['email' => 'This user is already a member of this organization.']);
        }

        OrganizationInvitation::where('organization_id', $organization->id)
            ->where('email', $email)
            ->delete();

        $invitation = OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'email' => $email,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $linkRoute = $existingUser ? 'organization.login' : 'organization.register';
        $link = route($linkRoute, ['organization' => $organization->id, 'invitation' => $invitation->token]);

        Mail::to($email)->send(new OrganizationInvitationMail($inviter, $organization, $link));

        return back()->with('success', 'Invitation sent to '.$email);
    }
}
