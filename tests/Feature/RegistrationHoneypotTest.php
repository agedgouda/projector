<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('registers a new user normally when the honeypot field is left empty', function () {
    $this->post(route('register.store'), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect();

    expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
});

it('rejects registration when the hidden honeypot field is filled in, like a bot would', function () {
    $this->post(route('register.store'), [
        'first_name' => 'Bot',
        'last_name' => 'Account',
        'email' => 'bot@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'website' => 'http://spam.example.com',
    ])->assertSessionHasErrors('website');

    expect(User::where('email', 'bot@example.com')->exists())->toBeFalse();
});
