<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('registers a user when Cloudflare confirms the Turnstile token', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $this->post(route('register.store'), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'cf-turnstile-response' => 'valid-token',
    ])->assertRedirect();

    expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
});

it('rejects registration when Cloudflare rejects the Turnstile token', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $this->post(route('register.store'), [
        'first_name' => 'Bot',
        'last_name' => 'Account',
        'email' => 'bot@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'cf-turnstile-response' => 'bad-token',
    ])->assertSessionHasErrors('cf-turnstile-response');

    expect(User::where('email', 'bot@example.com')->exists())->toBeFalse();
});

it('rejects registration when the Turnstile token is missing entirely', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);

    $this->post(route('register.store'), [
        'first_name' => 'Bot',
        'last_name' => 'Account',
        'email' => 'bot2@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('cf-turnstile-response');

    expect(User::where('email', 'bot2@example.com')->exists())->toBeFalse();
});
