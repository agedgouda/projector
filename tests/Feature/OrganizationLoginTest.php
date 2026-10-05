<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Corp']);
    $this->user = User::factory()->create(['password' => bcrypt('password')]);
    $this->invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => $this->user->email,
        'token' => 'login-test-token',
        'expires_at' => now()->addDays(7),
    ]);
});

it('shows the login form with the organization name', function () {
    $this->get(route('organization.login', ['organization' => $this->org, 'invitation' => $this->invitation->token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/OrganizationLogin')
            ->where('organization.id', $this->org->id)
            ->where('organization.name', 'Acme Corp')
        );
});

it('redirects to the regular login page without a valid invitation', function (?string $token) {
    $this->get(route('organization.login', ['organization' => $this->org, 'invitation' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
})->with([
    'no token' => [null],
    'unknown token' => ['not-a-real-token'],
]);

it('returns a 404 for an invalid organization id', function () {
    $this->get('/login/nonexistent-org-id')
        ->assertNotFound();
});

it('logs in a user and adds them to the organization as team-member', function () {
    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
        'invitation_token' => $this->invitation->token,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($this->user);
    expect($this->org->users()->where('user_id', $this->user->id)->exists())->toBeTrue();
    expect($this->org->users()->where('user_id', $this->user->id)->first()->pivot->role)->toBe('team-member');
});

it('does not join a user to the organization without an invitation', function () {
    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertSessionHasErrors(['invitation_token']);

    $this->assertGuest();
    expect($this->org->users()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('logs in an existing org member without changing their role', function () {
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
        'invitation_token' => $this->invitation->token,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($this->user);
    expect($this->org->users()->where('user_id', $this->user->id)->first()->pivot->role)->toBe('org-admin');
});

it('does not add a user twice if already a member', function () {
    $this->org->users()->attach($this->user->id, ['role' => 'team-member']);

    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
        'invitation_token' => $this->invitation->token,
    ])->assertRedirect();

    expect($this->org->users()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('rejects invalid credentials', function () {
    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'wrong-password',
        'invitation_token' => $this->invitation->token,
    ])->assertSessionHasErrors(['email']);

    $this->assertGuest();
});

it('validates required fields', function () {
    $this->post(route('organization.login.store', $this->org), [])
        ->assertSessionHasErrors(['email', 'password', 'invitation_token']);
});
