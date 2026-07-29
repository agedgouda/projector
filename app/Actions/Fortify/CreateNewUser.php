<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\TurnstileChallenge;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    private const MAX_ATTEMPTS_PER_HOUR = 5;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $rateLimitKey = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS_PER_HOUR)) {
            throw ValidationException::withMessages([
                'email' => 'Too many registration attempts. Please try again later.',
            ]);
        }

        RateLimiter::hit($rateLimitKey, 3600);

        // "required" only when a secret key is configured: without one (e.g. local dev
        // without Cloudflare credentials) the field shouldn't be validated at all, since
        // TurnstileChallenge itself would just no-op anyway.
        $turnstileRules = config('services.turnstile.secret_key')
            ? ['required', new TurnstileChallenge(request()->ip())]
            : [];

        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && $this->emailIsTaken($value)) {
                        $fail('The :attribute has already been taken.');
                    }
                },
            ],
            'password' => $this->passwordRules(),
            // Honeypot: a hidden field no real visitor can see or fill in (see
            // resources/js/pages/auth/Register.vue). A script that blindly fills every
            // field it finds trips this and fails validation like any other bad input.
            'website' => ['prohibited'],
            'cf-turnstile-response' => $turnstileRules,
        ])->validate();

        return User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'password' => $input['password'], // Password is automatically hashed by the User model or Fortify
        ]);
    }

    /**
     * Checks for an exact match plus, for Gmail addresses, a match after normalizing
     * away dots and "+tag" suffixes in the local part - Gmail treats "a.b.c@gmail.com"
     * and "abc+anything@gmail.com" as the same inbox as "abc@gmail.com", a trick bots
     * were using to register many "unique" fake accounts from one real inbox.
     */
    private function emailIsTaken(string $email): bool
    {
        $normalized = $this->normalizeGmailAddress($email);

        if ($normalized === strtolower(trim($email))) {
            // Not a Gmail address: normalization is a no-op, so an exact match suffices.
            return User::where('email', $email)->exists();
        }

        return User::query()
            ->where(function ($query): void {
                $query->where('email', 'like', '%@gmail.com')
                    ->orWhere('email', 'like', '%@googlemail.com');
            })
            ->get(['email'])
            ->contains(fn (User $user): bool => $this->normalizeGmailAddress($user->email) === $normalized);
    }

    private function normalizeGmailAddress(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if (! in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return $email;
        }

        $plusPosition = strpos($local, '+');
        $local = $plusPosition === false ? $local : substr($local, 0, $plusPosition);
        $local = str_replace('.', '', $local);

        return "{$local}@gmail.com";
    }
}
