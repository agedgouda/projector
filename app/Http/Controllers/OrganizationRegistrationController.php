<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationRegistrationController extends Controller
{
    public function create(Request $request, Organization $organization): Response|RedirectResponse
    {
        $invitation = $this->findValidInvitation($organization, $request->query('invitation'));

        if ($invitation && Auth::check() && strtolower(Auth::user()->email) === strtolower($invitation->email)) {
            return $this->addUserToOrgAndRedirect($request, $organization, Auth::user(), $invitation);
        }

        return Inertia::render('auth/OrganizationRegister', [
            'organization' => $organization->only('id', 'name'),
            'invitedEmail' => $invitation?->email,
            'invitationToken' => $invitation?->token,
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'invitation_token' => ['nullable', 'string'],
        ]);

        $this->validateInvitationToken($organization, $validated['invitation_token'] ?? null, $validated['email']);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
        }

        $invitation = OrganizationInvitation::where('token', $validated['invitation_token'] ?? null)->first();

        Auth::login($user);

        $request->session()->regenerate();

        return $this->addUserToOrgAndRedirect($request, $organization, $user, $invitation);
    }

    private function addUserToOrgAndRedirect(Request $request, Organization $organization, User $user, ?OrganizationInvitation $invitation): RedirectResponse
    {
        $wasAdded = ! $organization->users()->where('user_id', $user->id)->exists();

        if ($wasAdded) {
            $organization->users()->attach($user->id, ['role' => $invitation?->role ?? 'team-member']);
        }

        if ($invitation) {
            Document::where('pending_assignee_invitation_id', $invitation->id)
                ->update(['assignee_id' => $user->id, 'pending_assignee_invitation_id' => null]);

            $invitation->delete();
        }

        $message = $wasAdded
            ? 'You have successfully been added to '.$organization->name.'.'
            : null;

        return redirect()->route('dashboard', ['org' => $organization->id])->with('success', $message);
    }

    private function findValidInvitation(Organization $organization, ?string $token): ?OrganizationInvitation
    {
        if (! $token) {
            return null;
        }

        return OrganizationInvitation::where('token', $token)
            ->where('organization_id', $organization->id)
            ->where(function ($query) {
                $query->where('expires_at', '>', now())
                    ->orWhereHas('documentAssignments');
            })
            ->first();
    }

    private function validateInvitationToken(Organization $organization, ?string $token, string $email): void
    {
        if (! $token) {
            return;
        }

        $invitation = $this->findValidInvitation($organization, $token);

        if (! $invitation || strtolower($invitation->email) !== strtolower($email)) {
            throw ValidationException::withMessages([
                'email' => 'This invitation is not valid for this email address.',
            ]);
        }
    }
}
