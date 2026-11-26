<?php

namespace App\Services\Apollo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Centralizes every Apollo.io API call this app makes. Apollo authenticates with the key in an
 * x-api-key header — sending it as a Bearer token is rejected with a 401.
 *
 * @see https://docs.apollo.io/reference/authentication
 */
class ApolloApiClient
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * Whether the configured key is valid and signed in — Apollo's auth/health endpoint answers
     * {"healthy": true, "is_logged_in": true} for a working key.
     *
     * @return array{healthy: bool, is_logged_in: bool}
     */
    public function health(): array
    {
        $response = $this->request()->get('/auth/health');

        if ($response->failed()) {
            throw new RuntimeException("Apollo API returned HTTP {$response->status()}: {$response->body()}");
        }

        return [
            'healthy' => $response->json('healthy') === true,
            'is_logged_in' => $response->json('is_logged_in') === true,
        ];
    }

    /**
     * The contact saved in this Apollo workspace with exactly this email address, if any. Only
     * searches our own saved contacts (not Apollo's people database), which costs no credits.
     *
     * @see https://docs.apollo.io/reference/search-for-contacts
     *
     * @return array{first_name: string|null, last_name: string|null, email: string, company: string|null}|null
     */
    public function findContactByEmail(string $email, int $timeoutSeconds = self::TIMEOUT_SECONDS): ?array
    {
        $response = $this->request()
            ->timeout($timeoutSeconds)
            ->post('/contacts/search', ['q_keywords' => $email, 'per_page' => 10]);

        if ($response->failed()) {
            throw new RuntimeException("Apollo API returned HTTP {$response->status()}: {$response->body()}");
        }

        $contacts = $response->json('contacts');

        foreach (is_array($contacts) ? $contacts : [] as $contact) {
            if (! is_array($contact) || ! is_string($contact['email'] ?? null) || strcasecmp($contact['email'], $email) !== 0) {
                continue;
            }

            return [
                'first_name' => $this->stringOrNull($contact['first_name'] ?? null),
                'last_name' => $this->stringOrNull($contact['last_name'] ?? null),
                'email' => $contact['email'],
                'company' => $this->stringOrNull($contact['organization_name'] ?? null)
                    ?? $this->stringOrNull(is_array($contact['account'] ?? null) ? ($contact['account']['name'] ?? null) : null),
            ];
        }

        return null;
    }

    public function isConfigured(): bool
    {
        $key = config('services.apollo.key');

        return is_string($key) && $key !== '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('APOLLO_API_KEY is not set.');
        }

        return Http::baseUrl(config()->string('services.apollo.base_url'))
            ->withHeaders(['x-api-key' => config()->string('services.apollo.key')])
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
