<?php

use App\Mail\OrganizationInvitationMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Corp']);

    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->orgAdmin = User::factory()->create();
    $this->org->users()->attach($this->orgAdmin->id, ['role' => 'org-admin']);
});

it('forbids a regular member from sending an invitation for the organization', function () {
    Mail::fake();

    $regularMember = User::factory()->create();
    $this->org->users()->attach($regularMember->id, ['role' => 'team-member']);

    $this->actingAs($regularMember)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('forbids a user unrelated to the organization from sending an invitation for it', function () {
    Mail::fake();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('allows a super-admin to send an invitation for any organization', function () {
    Mail::fake();

    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class);
});

it('forbids a regular member from resending an invitation for the organization', function () {
    Mail::fake();

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invitee@example.com',
        'role' => 'team-member',
        'token' => 'test-token',
        'expires_at' => now()->addDays(7),
    ]);

    $regularMember = User::factory()->create();
    $this->org->users()->attach($regularMember->id, ['role' => 'team-member']);

    $this->actingAs($regularMember)
        ->post(route('organizations.invitations.resend', [$this->org, $invitation]))
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('sends a registration link for a new email', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class, function ($mail) {
        return $mail->hasTo('newuser@example.com')
            && str_contains($mail->link, '/invite/');
    });

    $invitation = OrganizationInvitation::where('email', 'newuser@example.com')->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->first_name)->toBe('New')
        ->and($invitation->last_name)->toBe('User');
});

it('attaches an existing user directly instead of sending an invitation', function () {
    Mail::fake();

    $existingUser = User::factory()->create(['email' => 'existing@example.com', 'first_name' => 'Real', 'last_name' => 'Name']);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'Typed', 'last_name' => 'Name', 'email' => 'existing@example.com', 'role' => 'project-lead'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Typed Name (existing@example.com) is already registered and has been added to Acme Corp.');

    Mail::assertNothingSent();

    expect(OrganizationInvitation::where('email', 'existing@example.com')->exists())->toBeFalse();

    $pivot = $this->org->users()->where('user_id', $existingUser->id)->first()?->pivot;
    expect($pivot)->not->toBeNull()
        ->and($pivot->role)->toBe('project-lead');

    // The typed name is never written back to the existing account.
    $existingUser->refresh();
    expect($existingUser->first_name)->toBe('Real')
        ->and($existingUser->last_name)->toBe('Name');
});

it('returns an error if the user is already a member', function () {
    Mail::fake();

    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->org->users()->attach($member->id);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'Member', 'last_name' => 'Person', 'email' => 'member@example.com', 'role' => 'team-member'])
        ->assertSessionHasErrors('email');

    Mail::assertNotSent(OrganizationInvitationMail::class);
});

it('replaces an existing pending invitation for the same email', function () {
    Mail::fake();

    OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'newuser@example.com',
        'token' => str_repeat('a', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    expect(OrganizationInvitation::where('email', 'newuser@example.com')->count())->toBe(1);
});

// --- Update (edit + resend) ---

it('forbids a regular member from editing an invitation for the organization', function () {
    Mail::fake();

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invitee@example.com',
        'first_name' => 'Old',
        'last_name' => 'Name',
        'role' => 'team-member',
        'token' => 'test-token',
        'expires_at' => now()->addDays(7),
    ]);

    $regularMember = User::factory()->create();
    $this->org->users()->attach($regularMember->id, ['role' => 'team-member']);

    $this->actingAs($regularMember)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'invitee@example.com', 'role' => 'team-member',
        ])
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('updates an invitation\'s details and resends it', function () {
    Mail::fake();

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'old@example.com',
        'first_name' => 'Old',
        'last_name' => 'Name',
        'role' => 'team-member',
        'token' => str_repeat('r', 64),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($this->orgAdmin)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'new@example.com', 'role' => 'project-lead',
        ])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class, fn ($mail) => $mail->hasTo('new@example.com'));

    $invitation->refresh();
    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->first_name)->toBe('New')
        ->and($invitation->role)->toBe('project-lead')
        ->and($invitation->token)->toBe(str_repeat('r', 64))
        ->and($invitation->expires_at->isFuture())->toBeTrue();
});

it('attaches an existing user directly when editing an invitation to their email', function () {
    Mail::fake();

    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'old@example.com',
        'role' => 'team-member',
        'token' => str_repeat('s', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->orgAdmin)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'existing@example.com', 'role' => 'org-admin',
        ])
        ->assertRedirect();

    Mail::assertNothingSent();

    expect(OrganizationInvitation::find($invitation->id))->toBeNull();

    $pivot = $this->org->users()->where('user_id', $existingUser->id)->first()?->pivot;
    expect($pivot)->not->toBeNull()->and($pivot->role)->toBe('org-admin');
});

it('rejects editing an invitation to an email already belonging to an org member', function () {
    Mail::fake();

    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->org->users()->attach($member->id);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'old@example.com',
        'role' => 'team-member',
        'token' => str_repeat('t', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->orgAdmin)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'member@example.com', 'role' => 'team-member',
        ])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
    expect(OrganizationInvitation::find($invitation->id))->not->toBeNull();
});

it('deletes another pending invitation for the org that already used the edited email', function () {
    Mail::fake();

    OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'taken@example.com',
        'token' => str_repeat('u', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'old@example.com',
        'role' => 'team-member',
        'token' => str_repeat('v', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->orgAdmin)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'taken@example.com', 'role' => 'team-member',
        ])
        ->assertRedirect();

    expect(OrganizationInvitation::where('organization_id', $this->org->id)->where('email', 'taken@example.com')->count())->toBe(1)
        ->and(OrganizationInvitation::find($invitation->id))->not->toBeNull();
});

it('returns 404 when editing an invitation belonging to a different organization', function () {
    Mail::fake();

    $otherOrg = Organization::create(['name' => 'Other Org']);
    $invitation = OrganizationInvitation::create([
        'organization_id' => $otherOrg->id,
        'email' => 'foreign@example.com',
        'token' => str_repeat('w', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->orgAdmin)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'foreign@example.com', 'role' => 'team-member',
        ])
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('registers a user via an invitation token', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'token' => str_repeat('b', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    expect(OrganizationInvitation::find($invitation->id))->toBeNull();
});

it('rejects registration with an invitation token for the wrong email', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'token' => str_repeat('c', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'someone-else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertSessionHasErrors('email');
});

it('logs in a user via an invitation token and adds them to the org', function () {
    $user = User::factory()->create(['email' => 'loginuser@example.com', 'password' => bcrypt('password')]);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'loginuser@example.com',
        'token' => str_repeat('d', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.login.store', $this->org), [
        'email' => 'loginuser@example.com',
        'password' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect($this->org->users()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(OrganizationInvitation::find($invitation->id))->toBeNull();
});

it('rejects login with an invitation token for the wrong email', function () {
    $user = User::factory()->create(['email' => 'other@example.com', 'password' => bcrypt('password')]);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'token' => str_repeat('e', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.login.store', $this->org), [
        'email' => 'other@example.com',
        'password' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertSessionHasErrors('email');
});

it('rejects an expired invitation token', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'token' => str_repeat('f', 64),
        'expires_at' => now()->subDay(),
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertSessionHasErrors('email');
});

it('requires authentication to send an invitation', function () {
    $this->post(route('organizations.invite', $this->org), ['first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    $this->assertGuest();
});

it('stores the invited role on the invitation', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'Lead', 'last_name' => 'Person', 'email' => 'lead@example.com', 'role' => 'project-lead'])
        ->assertRedirect();

    expect(OrganizationInvitation::where('email', 'lead@example.com')->first()->role)->toBe('project-lead');
});

it('rejects an invalid role when inviting', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@example.com', 'role' => 'super-villain'])
        ->assertSessionHasErrors('role');
});

it('requires a first and last name when inviting', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'test@example.com', 'role' => 'team-member'])
        ->assertSessionHasErrors(['first_name', 'last_name']);

    Mail::assertNothingSent();
});

it('includes name and role in the invitations list on the organization page', function () {
    OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'shown@example.com',
        'first_name' => 'Shown',
        'last_name' => 'Person',
        'role' => 'project-lead',
        'token' => str_repeat('q', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($this->orgAdmin)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('organizations.index', ['org' => $this->org->id]));

    $response->assertOk();

    $invitation = collect($response->original->getData()['page']['props']['invitations'])
        ->firstWhere('email', 'shown@example.com');

    expect($invitation)->not->toBeNull()
        ->and($invitation['first_name'])->toBe('Shown')
        ->and($invitation['last_name'])->toBe('Person')
        ->and($invitation['role'])->toBe('project-lead');
});

it('prefills the registration form with the invited name', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'first_name' => 'Invited',
        'last_name' => 'Person',
        'token' => str_repeat('p', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('organization.register', ['organization' => $this->org, 'invitation' => $invitation->token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/OrganizationRegister')
            ->where('invitedEmail', 'invited@example.com')
            ->where('invitedFirstName', 'Invited')
            ->where('invitedLastName', 'Person')
        );
});

it('assigns the invited role when a new user registers via invitation', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'role' => 'project-lead',
        'token' => str_repeat('g', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $pivot = $this->org->users()->where('email', 'invited@example.com')->first()?->pivot;
    expect($pivot->role)->toBe('project-lead');
});

it('assigns the invited role when an existing user logs in via invitation', function () {
    $user = User::factory()->create(['email' => 'leaduser@example.com', 'password' => bcrypt('password')]);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'leaduser@example.com',
        'role' => 'org-admin',
        'token' => str_repeat('h', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.login.store', $this->org), [
        'email' => 'leaduser@example.com',
        'password' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $pivot = $this->org->users()->where('user_id', $user->id)->first()?->pivot;
    expect($pivot->role)->toBe('org-admin');
});

it('falls back to team-member when invitation has no role', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'fallback@example.com',
        'token' => str_repeat('i', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'John',
        'last_name' => 'Smith',
        'email' => 'fallback@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $pivot = $this->org->users()->where('email', 'fallback@example.com')->first()?->pivot;
    expect($pivot->role)->toBe('team-member');
});

// --- Pending assignee tests ---

function makeDocument(Organization $org): array
{
    $client = Client::create(['organization_id' => $org->id, 'company_name' => 'Corp', 'contact_name' => 'Alice', 'contact_phone' => null]);
    $project = Project::create(['name' => 'Project', 'client_id' => $client->id]);
    $document = Document::create(['project_id' => $project->id, 'name' => 'Task', 'type' => 'action_items', 'content' => 'Do something']);

    return [$project, $document];
}

it('keeps an expired invitation usable when it has a pending document assignment', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'assigned@example.com',
        'token' => str_repeat('j', 64),
        'expires_at' => now()->subDay(),
    ]);

    [, $document] = makeDocument($this->org);
    $document->update(['pending_assignee_invitation_id' => $invitation->id]);

    // The accept endpoint should still redirect (not return expired error)
    $this->get(route('invite', $invitation->token))->assertRedirect();
});

it('rejects an expired invitation with no document assignments', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'expired@example.com',
        'token' => str_repeat('k', 64),
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('invite', $invitation->token))
        ->assertRedirect(route('login'));
});

it('transfers pending document assignment to real user on registration', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'newassignee@example.com',
        'token' => str_repeat('l', 64),
        'expires_at' => now()->addDays(7),
    ]);

    [, $document] = makeDocument($this->org);
    $document->update(['pending_assignee_invitation_id' => $invitation->id]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'New',
        'last_name' => 'Person',
        'email' => 'newassignee@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $user = User::where('email', 'newassignee@example.com')->first();
    $document->refresh();

    expect($document->assignee_id)->toBe($user->id)
        ->and($document->pending_assignee_invitation_id)->toBeNull();
});

it('transfers pending document assignment to real user on login', function () {
    $user = User::factory()->create(['email' => 'loginassignee@example.com', 'password' => bcrypt('password')]);

    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'loginassignee@example.com',
        'token' => str_repeat('m', 64),
        'expires_at' => now()->addDays(7),
    ]);

    [, $document] = makeDocument($this->org);
    $document->update(['pending_assignee_invitation_id' => $invitation->id]);

    $this->post(route('organization.login.store', $this->org), [
        'email' => 'loginassignee@example.com',
        'password' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $document->refresh();

    expect($document->assignee_id)->toBe($user->id)
        ->and($document->pending_assignee_invitation_id)->toBeNull();
});

it('clears pending assignment when an invitation is accepted even if expired via assignment bypass', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'expiredassigned@example.com',
        'token' => str_repeat('n', 64),
        'expires_at' => now()->subDay(),
    ]);

    [, $document] = makeDocument($this->org);
    $document->update(['pending_assignee_invitation_id' => $invitation->id]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Old',
        'last_name' => 'Invite',
        'email' => 'expiredassigned@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect();

    $user = User::where('email', 'expiredassigned@example.com')->first();
    $document->refresh();

    expect($document->assignee_id)->toBe($user->id)
        ->and($document->pending_assignee_invitation_id)->toBeNull();
});
