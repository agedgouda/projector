<?php

use App\Mail\OrganizationInvitationMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\ProjectType;
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

it('sends a registration link for a new email', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class, function ($mail) {
        return $mail->hasTo('newuser@example.com')
            && str_contains($mail->link, '/invite/');
    });

    expect(OrganizationInvitation::where('email', 'newuser@example.com')->exists())->toBeTrue();
});

it('sends a login link for an existing user not in the org', function () {
    Mail::fake();

    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'existing@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class, function ($mail) {
        return $mail->hasTo('existing@example.com')
            && str_contains($mail->link, '/invite/');
    });
});

it('returns an error if the user is already a member', function () {
    Mail::fake();

    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->org->users()->attach($member->id);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'member@example.com', 'role' => 'team-member'])
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
        ->post(route('organizations.invite', $this->org), ['email' => 'newuser@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    expect(OrganizationInvitation::where('email', 'newuser@example.com')->count())->toBe(1);
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
    $this->post(route('organizations.invite', $this->org), ['email' => 'test@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    $this->assertGuest();
});

it('stores the invited role on the invitation', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'lead@example.com', 'role' => 'project-lead'])
        ->assertRedirect();

    expect(OrganizationInvitation::where('email', 'lead@example.com')->first()->role)->toBe('project-lead');
});

it('rejects an invalid role when inviting', function () {
    Mail::fake();

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'test@example.com', 'role' => 'super-villain'])
        ->assertSessionHasErrors('role');
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
    $projectType = ProjectType::create(['name' => 'Test Type', 'icon' => 'Briefcase', 'organization_id' => null]);
    $client = Client::create(['organization_id' => $org->id, 'company_name' => 'Corp', 'contact_name' => 'Alice', 'contact_phone' => null]);
    $project = Project::create(['name' => 'Project', 'client_id' => $client->id, 'project_type_id' => $projectType->id]);
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
