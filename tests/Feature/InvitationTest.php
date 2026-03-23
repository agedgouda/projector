<?php

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
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
        ->post(route('organizations.invite', $this->org), ['email' => 'newuser@example.com'])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class, function ($mail) {
        return $mail->hasTo('newuser@example.com')
            && str_contains($mail->link, 'register');
    });

    expect(OrganizationInvitation::where('email', 'newuser@example.com')->exists())->toBeTrue();
});

it('sends a login link for an existing user not in the org', function () {
    Mail::fake();

    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'existing@example.com'])
        ->assertRedirect();

    Mail::assertSent(OrganizationInvitationMail::class, function ($mail) {
        return $mail->hasTo('existing@example.com')
            && str_contains($mail->link, 'login');
    });
});

it('returns an error if the user is already a member', function () {
    Mail::fake();

    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->org->users()->attach($member->id);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['email' => 'member@example.com'])
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
        ->post(route('organizations.invite', $this->org), ['email' => 'newuser@example.com'])
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
    $this->post(route('organizations.invite', $this->org), ['email' => 'test@example.com'])
        ->assertRedirect();

    $this->assertGuest();
});
