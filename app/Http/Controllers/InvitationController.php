<?php

namespace App\Http\Controllers;

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        // Same ability as OrganizationController::addUser — only an org-admin (of this
        // specific organization) or a super-admin (via the policy's before() bypass) may
        // send an invitation for it.
        Gate::authorize('manageUsers', $organization);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
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

        // An account for this email already exists — attach it directly instead of sending
        // an invitation to accept. The entered name is used only for this confirmation
        // message; it's never written back to the existing account, since that account
        // belongs to whoever registered it, not to whoever typed a name into this form.
        if ($existingUser) {
            $organization->users()->attach($existingUser->id, ['role' => $validated['role']]);

            return back()->with(
                'success',
                "{$validated['first_name']} {$validated['last_name']} ({$email}) is already registered and has been added to {$organization->name}."
            );
        }

        $invitation = OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'email' => $email,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'role' => $validated['role'],
            'token' => Str::random(16),
            'expires_at' => now()->addDays(7),
        ]);

        $link = route('invite', $invitation->token);

        Mail::to($email)->send(new OrganizationInvitationMail($inviter, $organization, $link));

        return back()->with('success', 'Invitation sent to '.$email);
    }

    /**
     * Update a pending invitation's details and resend it — the same form the "Invite User"
     * button opens, pre-filled and repurposed for editing. Mirrors store()'s edge-case
     * handling (an email that now matches an existing member/user) since the email field
     * is editable here too.
     */
    public function update(Request $request, Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageUsers', $organization);

        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:team-member,project-lead,org-admin'],
        ]);

        $email = $validated['email'];
        $inviter = $request->user();

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && $organization->users()->where('user_id', $existingUser->id)->exists()) {
            return back()->withErrors(['email' => 'This user is already a member of this organization.']);
        }

        // Another pending invitation for this organization may already hold the new email —
        // same dedupe store() does when creating fresh.
        OrganizationInvitation::where('organization_id', $organization->id)
            ->where('email', $email)
            ->where('id', '!=', $invitation->id)
            ->delete();

        // The edited email now belongs to an existing account — attach directly instead of
        // resending an invitation, same as store()'s handling for a brand new invite.
        if ($existingUser) {
            $organization->users()->attach($existingUser->id, ['role' => $validated['role']]);
            $invitation->delete();

            return back()->with(
                'success',
                "{$validated['first_name']} {$validated['last_name']} ({$email}) is already registered and has been added to {$organization->name}."
            );
        }

        $invitation->update([
            'email' => $email,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'role' => $validated['role'],
            'expires_at' => now()->addDays(7),
        ]);

        $link = route('invite', $invitation->token);

        Mail::to($email)->send(new OrganizationInvitationMail($inviter, $organization, $link));

        return back()->with('success', 'Invitation updated and resent to '.$email);
    }

    public function resend(Request $request, Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageUsers', $organization);

        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $inviter = $request->user();
        $link = route('invite', $invitation->token);

        Mail::to($invitation->email)->send(new OrganizationInvitationMail($inviter, $organization, $link));

        return back()->with('success', 'Invitation resent to '.$invitation->email);
    }

    public function destroy(Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageUsers', $organization);

        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $invitation->delete();

        return back()->with('success', 'Invitation to '.$invitation->email.' revoked.');
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
