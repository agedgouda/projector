<?php

namespace App\Http\Controllers;

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:team-member,project-lead,org-admin'],
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
            'role' => $validated['role'],
            'token' => Str::random(16),
            'expires_at' => now()->addDays(7),
        ]);

        $link = route('invite', $invitation->token);

        Mail::to($email)->send(new OrganizationInvitationMail($inviter, $organization, $link));

        return back()->with('success', 'Invitation sent to '.$email);
    }

    public function resend(Request $request, Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $inviter = $request->user();
        $link = route('invite', $invitation->token);

        Mail::to($invitation->email)->send(new OrganizationInvitationMail($inviter, $organization, $link));

        return back()->with('success', 'Invitation resent to '.$invitation->email);
    }

    public function accept(string $token): RedirectResponse
    {
        $invitation = OrganizationInvitation::where('token', $token)
            ->where(function ($query) {
                $query->where('expires_at', '>', now())
                    ->orWhereHas('documentAssignments');
            })
            ->first();

        if (! $invitation) {
            return redirect()->route('login')->withErrors(['email' => 'This invitation link is invalid or has expired.']);
        }

        $existingUser = User::where('email', $invitation->email)->first();

        $route = $existingUser ? 'organization.login' : 'organization.register';

        return redirect()->route($route, [
            'organization' => $invitation->organization_id,
            'invitation' => $token,
        ]);
    }
}
