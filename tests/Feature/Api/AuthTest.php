<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('issues a token for valid credentials', function () {
    $user = User::factory()->withoutTwoFactor()->create(['password' => bcrypt('correct-password')]);

    $response = $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'device_name' => 'iPhone 17',
    ]);

    $response->assertOk();
    expect($response->json('token'))->not->toBeEmpty();
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->where('name', 'iPhone 17')->exists())->toBeTrue();
});

it('rejects an incorrect password', function () {
    $user = User::factory()->withoutTwoFactor()->create(['password' => bcrypt('correct-password')]);

    $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'iPhone 17',
    ])->assertUnprocessable();
});

it('requires a two-factor code when the user has 2FA enabled', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'device_name' => 'iPhone 17',
    ])->assertUnprocessable();
});

it('issues a token when a valid two-factor code is provided', function () {
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'device_name' => 'iPhone 17',
        'two_factor_code' => $google2fa->getCurrentOtp($secret),
    ]);

    $response->assertOk();
    expect($response->json('token'))->not->toBeEmpty();
});

it('rejects an invalid two-factor code', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'correct-password',
        'device_name' => 'iPhone 17',
        'two_factor_code' => '000000',
    ])->assertUnprocessable();
});

it('revokes the current token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone 17');

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->postJson(route('api.logout'))
        ->assertOk();

    expect(PersonalAccessToken::where('id', $token->accessToken->id)->exists())->toBeFalse();
});

it('rejects unauthenticated requests to protected routes', function () {
    $this->getJson(route('api.projects.index'))->assertUnauthorized();
});
