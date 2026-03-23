<?php

use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Corp']);
    $this->user = User::factory()->create(['password' => bcrypt('password')]);
});

it('shows the login form with the organization name', function () {
    $this->get(route('organization.login', $this->org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/OrganizationLogin')
            ->where('organization.id', $this->org->id)
            ->where('organization.name', 'Acme Corp')
        );
});

it('returns a 404 for an invalid organization id', function () {
    $this->get('/login/nonexistent-org-id')
        ->assertNotFound();
});

it('logs in a user and adds them to the organization as team-member', function () {
    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($this->user);
    expect($this->org->users()->where('user_id', $this->user->id)->exists())->toBeTrue();
    expect($this->org->users()->where('user_id', $this->user->id)->first()->pivot->role)->toBe('team-member');
});

it('logs in an existing org member without changing their role', function () {
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($this->user);
    expect($this->org->users()->where('user_id', $this->user->id)->first()->pivot->role)->toBe('org-admin');
});

it('does not add a user twice if already a member', function () {
    $this->org->users()->attach($this->user->id, ['role' => 'team-member']);

    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertRedirect();

    expect($this->org->users()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('rejects invalid credentials', function () {
    $this->post(route('organization.login.store', $this->org), [
        'email' => $this->user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email']);

    $this->assertGuest();
});

it('validates required fields', function () {
    $this->post(route('organization.login.store', $this->org), [])
        ->assertSessionHasErrors(['email', 'password']);
});
