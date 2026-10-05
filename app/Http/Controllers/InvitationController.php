<?php

namespace App\Http\Controllers;

use App\Mail\OrganizationInvitationMail;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $pendingInvitations = OrganizationInvitation::where('organization_id', $organization->id)
            ->where('email', $email)
            ->orderBy('id')
            ->get();

        // An account for this email already exists — attach it directly instead of sending
        // an invitation to accept. The entered name is used only for this confirmation
        // message; it's never written back to the existing account, since that account
        // belongs to whoever registered it, not to whoever typed a name into this form.
        if ($existingUser) {
            $organization->users()->attach($existingUser->id, ['role' => $validated['role']]);
            $this->handOffPendingTasks($pendingInvitations, ['assignee_id' => $existingUser->id]);

            return back()->with(
                'success',
                "{$validated['first_name']} {$validated['last_name']} ({$email}) is already registered and has been added to {$organization->name}."
            );
        }

        // Re-inviting someone reuses their existing invitation (same id and link) rather than
        // replacing it, so tasks already assigned to them stay assigned.
        $invitation = $pendingInvitations->shift() ?? new OrganizationInvitation([
            'organization_id' => $organization->id,
            'email' => $email,
            'token' => Str::random(16),
        ]);

        $invitation->fill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'role' => $validated['role'],
            'expires_at' => now()->addDays(7),
        ])->save();

        $this->handOffPendingTasks($pendingInvitations, ['pending_assignee_invitation_id' => $invitation->id]);

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
        // same dedupe store() does, folding its assigned tasks into this one.
        $duplicateInvitations = OrganizationInvitation::where('organization_id', $organization->id)
            ->where('email', $email)
            ->where('id', '!=', $invitation->id)
            ->get();

        // The edited email now belongs to an existing account — attach directly instead of
        // resending an invitation, same as store()'s handling for a brand new invite.
        if ($existingUser) {
            $organization->users()->attach($existingUser->id, ['role' => $validated['role']]);
            $this->handOffPendingTasks($duplicateInvitations->push($invitation), ['assignee_id' => $existingUser->id]);

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

        $this->handOffPendingTasks($duplicateInvitations, ['pending_assignee_invitation_id' => $invitation->id]);

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

        $invitation->update(['expires_at' => now()->addDays(7)]);

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

    /**
     * Moves tasks assigned to $invitations onto either a real user (assignee_id) or another
     * invitation (pending_assignee_invitation_id), then deletes $invitations — without this,
     * the foreign key's nullOnDelete would silently unassign those tasks.
     *
     * @param  Collection<int, OrganizationInvitation>  $invitations
     * @param  array{assignee_id: int}|array{pending_assignee_invitation_id: int}  $newAssignee
     */
    private function handOffPendingTasks(Collection $invitations, array $newAssignee): void
    {
        if ($invitations->isEmpty()) {
            return;
        }

        $invitationIds = $invitations->pluck('id');

        Document::whereIn('pending_assignee_invitation_id', $invitationIds)
            ->update([
                'assignee_id' => $newAssignee['assignee_id'] ?? null,
                'pending_assignee_invitation_id' => $newAssignee['pending_assignee_invitation_id'] ?? null,
            ]);

        OrganizationInvitation::whereIn('id', $invitationIds)->delete();
    }
}
