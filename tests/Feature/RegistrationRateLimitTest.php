<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->register = function (string $email) {
        return $this->post(route('register.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    };
});

it('allows up to five registrations per hour from the same IP', function () {
    for ($i = 1; $i <= 5; $i++) {
        ($this->register)("rate-limit-user{$i}@example.com")->assertRedirect();
        $this->post(route('logout'));
    }

    expect(User::whereIn('email', array_map(
        fn (int $i) => "rate-limit-user{$i}@example.com",
        range(1, 5)
    ))->count())->toBe(5);
});

it('blocks registration after five attempts from the same IP within an hour', function () {
    for ($i = 1; $i <= 5; $i++) {
        ($this->register)("rate-limit-user{$i}@example.com")->assertRedirect();
        $this->post(route('logout'));
    }

    ($this->register)('rate-limit-user6@example.com')->assertSessionHasErrors('email');

    expect(User::where('email', 'rate-limit-user6@example.com')->exists())->toBeFalse();
});
