<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Verifies the Bot Framework JWT on every inbound Teams activity — Teams' equivalent of
 * VerifySlackSignature, but an RS256-signed bearer token checked against the Bot Connector's
 * published keys rather than an HMAC over a shared secret. Implements every check in
 * https://learn.microsoft.com/azure/bot-service/rest-api/bot-framework-rest-connector-authentication
 * ("Authenticate requests from the Bot Connector service to your bot").
 */
class VerifyTeamsToken
{
    private const OPENID_CONFIGURATION_URL = 'https://login.botframework.com/v1/.well-known/openidconfiguration';

    private const ISSUER = 'https://api.botframework.com';

    /**
     * Microsoft asks bots to refresh the signing keys at least once every 24 hours; refreshing
     * more often than that costs nothing and picks up newly added keys sooner.
     */
    private const KEYS_CACHE_SECONDS = 3600 * 12;

    /**
     * The "industry-standard" clock skew Microsoft's docs specify.
     */
    private const CLOCK_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $appId = config('services.teams.app_id');

        if (! is_string($appId) || blank($appId)) {
            abort(500, 'Teams app id is not configured.');
        }

        $token = $request->bearerToken();

        if (blank($token)) {
            abort(401, 'Missing Teams bearer token.');
        }

        $keySet = $this->keySet();

        try {
            JWT::$leeway = self::CLOCK_SKEW_SECONDS;
            $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $keySet], 'RS256'));
        } catch (Throwable) {
            abort(401, 'Invalid Teams token.');
        }

        if (($claims['iss'] ?? null) !== self::ISSUER || ($claims['aud'] ?? null) !== $appId) {
            abort(401, 'Invalid Teams token issuer or audience.');
        }

        if (($claims['serviceUrl'] ?? null) !== $request->input('serviceUrl')) {
            abort(401, 'Teams token serviceUrl does not match the activity.');
        }

        $channelId = $request->input('channelId');

        if (is_string($channelId) && ! in_array($channelId, $this->endorsementsFor($token, $keySet), true)) {
            abort(403, 'Teams signing key is not endorsed for this channel.');
        }

        return $next($request);
    }

    /**
     * @return list<array<mixed>>
     */
    private function keySet(): array
    {
        return Cache::remember('teams.bot_connector_keys', self::KEYS_CACHE_SECONDS, function (): array {
            $jwksUri = Http::get(self::OPENID_CONFIGURATION_URL)->throw()->json('jwks_uri');

            if (! is_string($jwksUri)) {
                abort(500, 'Bot Connector OpenID metadata has no jwks_uri.');
            }

            $keys = Http::get($jwksUri)->throw()->json('keys');

            if (! is_array($keys)) {
                abort(500, 'Bot Connector key set is malformed.');
            }

            return array_values(array_filter($keys, 'is_array'));
        });
    }

    /**
     * The `endorsements` Microsoft adds to each signing key — the channels (e.g. "msteams") that
     * key may sign for. Only called after JWT::decode() has already proven the token was signed
     * by the key whose `kid` this reads.
     *
     * @param  list<array<mixed>>  $keySet
     * @return list<string>
     */
    private function endorsementsFor(string $token, array $keySet): array
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;

        foreach ($keySet as $key) {
            if (($key['kid'] ?? null) === $kid) {
                $endorsements = $key['endorsements'] ?? [];

                return is_array($endorsements) ? array_values(array_filter($endorsements, 'is_string')) : [];
            }
        }

        return [];
    }
}
