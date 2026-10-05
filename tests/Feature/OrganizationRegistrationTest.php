<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Corp']);
    $this->invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'jane@example.com',
        'token' => 'registration-test-token',
        'expires_at' => now()->addDays(7),
    ]);
});

it('shows the registration form with the organization name', function () {
    $this->get(route('organization.register', ['organization' => $this->org, 'invitation' => $this->invitation->token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/OrganizationRegister')
            ->where('organization.id', $this->org->id)
            ->where('organization.name', 'Acme Corp')
        );
});

it('redirects to the regular login page without a valid invitation', function (?string $token) {
    $this->get(route('organization.register', ['organization' => $this->org, 'invitation' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
})->with([
    'no token' => [null],
    'unknown token' => ['not-a-real-token'],
]);

it('sends an invitee who already has an account to the organization login page', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->get(route('organization.register', ['organization' => $this->org, 'invitation' => $this->invitation->token]))
        ->assertRedirect(route('organization.login', ['organization' => $this->org->id, 'invitation' => $this->invitation->token]));
});

it('creates a new user and adds them to the organization', function () {
    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $this->invitation->token,
    ])->assertRedirect();

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->first_name)->toBe('Jane');
    expect($this->org->users()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($this->org->users()->where('user_id', $user->id)->first()->pivot->role)->toBe('team-member');
    $this->assertAuthenticatedAs($user);
});

it('does not create an account or join the organization without an invitation', function () {
    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['invitation_token']);

    $this->assertGuest();
    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
});

it('never signs in to an existing account, even with a valid invitation for its email', function () {
    $existing = User::factory()->create([
        'email' => 'jane@example.com',
        'first_name' => 'Original',
        'last_name' => 'User',
        'password' => bcrypt('their-real-password'),
    ]);

    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Changed',
        'last_name' => 'Name',
        'email' => 'jane@example.com',
        'password' => 'attacker-password',
        'password_confirmation' => 'attacker-password',
        'invitation_token' => $this->invitation->token,
    ])->assertRedirect(route('organization.login', ['organization' => $this->org->id, 'invitation' => $this->invitation->token]));

    $this->assertGuest();
    $existing->refresh();
    expect(User::where('email', 'jane@example.com')->count())->toBe(1)
        ->and($existing->first_name)->toBe('Original')
        ->and($existing->last_name)->toBe('User')
        ->and(Hash::check('their-real-password', $existing->password))->toBeTrue()
        ->and($this->org->users()->where('user_id', $existing->id)->exists())->toBeFalse();
});

it('rejects an invitation token for a different email address', function () {
    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'email' => 'someone-else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $this->invitation->token,
    ])->assertSessionHasErrors(['email']);

    $this->assertGuest();
});

it('returns a 404 for an invalid organization id', function () {
    $this->get('/register/nonexistent-org-id')
        ->assertNotFound();
});

it('validates required fields', function () {
    $this->post(route('organization.register.store', $this->org), [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'password', 'invitation_token']);
});

it('validates password confirmation', function () {
    $this->post(route('organization.register.store', $this->org), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
        'invitation_token' => $this->invitation->token,
    ])->assertSessionHasErrors(['password']);
});
