<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileChallenge implements ValidationRule
{
    public function __construct(private readonly ?string $remoteIp = null) {}

    /**
     * Verifies the token Cloudflare Turnstile's widget submits against Cloudflare's
     * siteverify endpoint. No-ops when TURNSTILE_SECRET_KEY isn't configured, so local
     * development works without needing Cloudflare credentials.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secretKey = config('services.turnstile.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            return;
        }

        if (! is_string($value) || $value === '') {
            $fail('Please complete the verification challenge.');

            return;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secretKey,
            'response' => $value,
            'remoteip' => $this->remoteIp,
        ]);

        if (! $response->successful() || $response->json('success') !== true) {
            Log::warning('Turnstile verification failed', [
                'status' => $response->status(),
                'error_codes' => $response->json('error-codes'),
                'remote_ip' => $this->remoteIp,
            ]);

            $fail('Verification failed. Please try again.');
        }
    }
}
