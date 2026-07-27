<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
});

it('renders the mobile verify-email view when the pending destination is the mobile app', function () {
    $user = User::factory()->withoutTwoFactor()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['url.intended' => 'http://projector.test/app'])
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mobile/VerifyEmail'));
});

it('renders the desktop verify-email view when there is no pending mobile destination', function () {
    $user = User::factory()->withoutTwoFactor()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/VerifyEmail'));
});

it('renders the mobile two-factor challenge view when the pending destination is the mobile app', function () {
    $user = User::factory()->create();

    $this->withSession([
        'login.id' => $user->id,
        'url.intended' => 'http://projector.test/app',
    ])
        ->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mobile/TwoFactorChallenge'));
});

it('renders the desktop two-factor challenge view when there is no pending mobile destination', function () {
    $user = User::factory()->create();

    $this->withSession(['login.id' => $user->id])
        ->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/TwoFactorChallenge'));
});

it('redirects to the mobile login page on logout when the referring page was in the mobile app', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'Referer' => 'http://projector.test/app/projects/xyz'])
        ->post(route('logout'));

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe('/app/login');
});

it('redirects to the desktop login page on logout when the referring page was not in the mobile app', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'Referer' => 'http://projector.test/dashboard'])
        ->post(route('logout'));

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe('/login');
});
