<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function attemptRegistrationWithEmail(string $email): \Illuminate\Testing\TestResponse
{
    return \Pest\Laravel\post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
}

it('rejects a Gmail dot-trick variant of an already-registered address', function () {
    User::factory()->create(['email' => 'realuser@gmail.com']);

    attemptRegistrationWithEmail('real.user@gmail.com')->assertSessionHasErrors('email');

    expect(User::where('email', 'real.user@gmail.com')->exists())->toBeFalse();
});

it('rejects a Gmail plus-tag variant of an already-registered address', function () {
    User::factory()->create(['email' => 'realuser@gmail.com']);

    attemptRegistrationWithEmail('realuser+spam@gmail.com')->assertSessionHasErrors('email');

    expect(User::where('email', 'realuser+spam@gmail.com')->exists())->toBeFalse();
});

it('rejects a googlemail.com variant of an already-registered gmail.com address', function () {
    User::factory()->create(['email' => 'realuser@gmail.com']);

    attemptRegistrationWithEmail('real.user+tag@googlemail.com')->assertSessionHasErrors('email');

    expect(User::where('email', 'real.user+tag@googlemail.com')->exists())->toBeFalse();
});

it('still allows a dot-containing email on non-Gmail domains', function () {
    User::factory()->create(['email' => 'realuser@example.com']);

    attemptRegistrationWithEmail('real.user@example.com')->assertRedirect();

    expect(User::where('email', 'real.user@example.com')->exists())->toBeTrue();
});
