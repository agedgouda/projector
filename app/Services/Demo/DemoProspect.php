<?php

namespace App\Services\Demo;

use App\Services\Apollo\ApolloApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * A guest who arrived at /demo from an Apollo email link (/demo#email={{email}}): their Apollo
 * contact is looked up once and kept in the session, so the demo page, their sandbox and the
 * sign-up form can be personalized. Nothing is stored in the database. The email travels in the
 * URL fragment, which browsers never send to the server, and the page posts it to
 * DemoController::identify() — so it never shows up in access logs.
 */
class DemoProspect
{
    public const SESSION_KEY = 'demo_prospect';

    private const LOOKUP_TIMEOUT_SECONDS = 5;

    /** Caps lookups per IP, so the page can't be used to check which emails are Apollo contacts. */
    private const MAX_LOOKUPS_PER_HOUR = 10;

    public function __construct(private readonly ApolloApiClient $apollo) {}

    /**
     * Replaces any earlier prospect with the Apollo contact for $email. On no match, a failed
     * lookup or too many lookups, the visitor simply gets the default demo.
     */
    public function identify(Request $request, string $email): void
    {
        $request->session()->forget(self::SESSION_KEY);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! $this->apollo->isConfigured()) {
            return;
        }

        if (RateLimiter::tooManyAttempts($this->rateLimitKey($request), self::MAX_LOOKUPS_PER_HOUR)) {
            return;
        }

        RateLimiter::hit($this->rateLimitKey($request), 3600);

        try {
            $contact = $this->apollo->findContactByEmail($email, self::LOOKUP_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            Log::warning('[DemoProspect] Apollo contact lookup failed', ['error' => $e->getMessage()]);

            return;
        }

        if ($contact !== null) {
            $request->session()->put(self::SESSION_KEY, $contact);
        }
    }

    /**
     * @return array{first_name: string|null, last_name: string|null, email: string, company: string|null}|null
     */
    public function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $prospect = $request->session()->get(self::SESSION_KEY);

        return is_array($prospect) && is_string($prospect['email'] ?? null) ? [
            'first_name' => is_string($prospect['first_name'] ?? null) ? $prospect['first_name'] : null,
            'last_name' => is_string($prospect['last_name'] ?? null) ? $prospect['last_name'] : null,
            'email' => $prospect['email'],
            'company' => is_string($prospect['company'] ?? null) ? $prospect['company'] : null,
        ] : null;
    }

    private function rateLimitKey(Request $request): string
    {
        return 'demo-prospect:'.$request->ip();
    }
}
