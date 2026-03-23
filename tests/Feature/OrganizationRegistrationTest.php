<?php

use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Corp']);
});

it('shows the registration form with the organization name', function () {
    $this->get(route('organization.register', $this->org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/OrganizationRegister')
            ->where('organization.id', $this->org->id)
            ->where('organization.name', 'Acme Corp')
        );
});

it('creates a new user and adds them to the organization', function () {
    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->first_name)->toBe('Jane');
    expect($this->org->users()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($this->org->users()->where('user_id', $user->id)->first()->pivot->role)->toBe('team-member');
    $this->assertAuthenticatedAs($user);
});

it('does not create a duplicate user if the email already exists', function () {
    $existing = User::factory()->create(['email' => 'existing@example.com']);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'New',
        'last_name' => 'Name',
        'email' => 'existing@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
    expect($this->org->users()->where('user_id', $existing->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($existing);
});

it('does not update existing user information when email already exists', function () {
    $existing = User::factory()->create([
        'email' => 'existing@example.com',
        'first_name' => 'Original',
        'last_name' => 'User',
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Changed',
        'last_name' => 'Name',
        'email' => 'existing@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $existing->refresh();
    expect($existing->first_name)->toBe('Original');
    expect($existing->last_name)->toBe('User');
});

it('does not attach an already-existing org member twice', function () {
    $existing = User::factory()->create(['email' => 'member@example.com']);
    $this->org->users()->attach($existing->id);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Member',
        'last_name' => 'User',
        'email' => 'member@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    expect($this->org->users()->where('user_id', $existing->id)->count())->toBe(1);
});

it('returns a 404 for an invalid organization id', function () {
    $this->get('/register/nonexistent-org-id')
        ->assertNotFound();
});

it('validates required fields', function () {
    $this->post(route('organization.register.store', $this->org), [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'password']);
});

it('validates password confirmation', function () {
    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors(['password']);
});
