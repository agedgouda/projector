<?php

namespace App\Services\Teams;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends messages into Teams conversations as the Projector bot via the Bot Connector REST API —
 * Teams' equivalent of Slack's chat.postMessage. There's no official PHP Bot Framework SDK, so
 * this is raw HTTP, matching how every Slack call in this codebase is made.
 *
 * Teams has no response_url: every reply, immediate or from a queued job, goes to the
 * conversation's own serviceUrl with a bot token.
 */
class TeamsBotClient
{
    /**
     * Tokens are issued for an hour; refreshed a little early so one never expires mid-request.
     */
    private const TOKEN_CACHE_SECONDS = 3300;

    /**
     * @throws \RuntimeException carrying the Bot Connector's HTTP status and body, so a failure
     *                           (e.g. the cross-tenant 401) is diagnosable from the log alone.
     */
    public function sendToConversation(string $serviceUrl, string $conversationId, string $text): void
    {
        $url = rtrim($serviceUrl, '/').'/v3/conversations/'.rawurlencode($conversationId).'/activities';

        $response = Http::withToken($this->accessToken())->post($url, [
            'type' => 'message',
            'text' => $text,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("Bot Connector rejected the message (HTTP {$response->status()}): {$response->body()}");
        }
    }

    /**
     * A single-tenant bot's token always comes from our own home tenant — see
     * config/services.php's `teams` block.
     */
    private function accessToken(): string
    {
        return Cache::remember('teams.bot_access_token', self::TOKEN_CACHE_SECONDS, function (): string {
            $tenantId = config('services.teams.tenant_id');

            if (! is_string($tenantId) || blank($tenantId)) {
                throw new \RuntimeException('TEAMS_TENANT_ID is not configured.');
            }

            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.teams.app_id'),
                'client_secret' => config('services.teams.app_secret'),
                'scope' => 'https://api.botframework.com/.default',
            ]);

            $token = $response->json('access_token');

            if ($response->failed() || ! is_string($token)) {
                throw new \RuntimeException("Teams bot token request failed (HTTP {$response->status()}): {$response->body()}");
            }

            return $token;
        });
    }
}
