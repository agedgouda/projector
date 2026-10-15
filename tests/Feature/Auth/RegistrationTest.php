<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'privacy_policy' => '1',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration records when the privacy policy was accepted', function () {
    $this->freezeTime();

    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'privacy_policy' => '1',
    ]);

    expect(\App\Models\User::sole()->privacy_policy_accepted_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('registration requires accepting the privacy policy', function (array $consent) {
    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$consent,
    ])->assertSessionHasErrors(['privacy_policy' => 'You must accept the Privacy Policy to create an account.']);

    $this->assertGuest();
    expect(\App\Models\User::count())->toBe(0);
})->with([
    'missing' => [[]],
    'unchecked' => [['privacy_policy' => '0']],
    'garbage' => [['privacy_policy' => 'nope']],
]);

test('the checkbox value the registration form submits is accepted', function () {
    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'privacy_policy' => 'on',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});
