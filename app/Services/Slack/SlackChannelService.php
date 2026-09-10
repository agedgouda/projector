<?php

namespace App\Services\Slack;

use App\Models\SlackWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lists channels visible to a workspace's installed bot, for the channel-binding settings page.
 * Requires the `channels:read` (public) and `groups:read` (private) bot scopes.
 */
class SlackChannelService
{
    private const API_BASE = 'https://slack.com/api';

    /**
     * A workspace can have far more channels than are useful to page through here, so this caps
     * out at a generous but bounded number of pages rather than following `next_cursor`
     * indefinitely.
     */
    private const MAX_PAGES = 5;

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listChannels(SlackWorkspace $workspace): array
    {
        $channels = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = Http::withToken($workspace->bot_access_token)
                ->get(self::API_BASE.'/conversations.list', array_filter([
                    'types' => 'public_channel,private_channel',
                    'exclude_archived' => 'true',
                    'limit' => 200,
                    'cursor' => $cursor,
                ]));

            if ($response->failed() || ! $response->json('ok')) {
                throw new \RuntimeException('Failed to fetch Slack channels: '.($response->json('error') ?? $response->body()));
            }

            foreach ($response->json('channels', []) as $channel) {
                $channels[] = [
                    'id' => $channel['id'],
                    'name' => $channel['name'],
                ];
            }

            $cursor = $response->json('response_metadata.next_cursor');

            if (blank($cursor)) {
                break;
            }
        }

        return $channels;
    }

    /**
     * conversations.list (above) returns every public channel visible to the bot regardless of
     * membership — visibility isn't the same as membership, and Slack only delivers channel
     * events (a file dropped in, a message posted) to channels the bot has actually joined. A
     * human picking a public channel from that list to bind has no way to know it also needs an
     * explicit join, so OrganizationSlackChannelsController::store() calls this right after
     * creating a binding rather than leaving it as a silent trap. Requires the `channels:join`
     * bot scope; only works for public channels — Slack has no API for a bot to add itself to a
     * private one, so that still needs a human to /invite it, same as before this existed.
     * Returns false (never throws) on any failure so a binding still succeeds even when
     * auto-join can't — the caller logs why.
     */
    public function joinChannel(SlackWorkspace $workspace, string $channelId): bool
    {
        $response = Http::withToken($workspace->bot_access_token)
            ->post(self::API_BASE.'/conversations.join', ['channel' => $channelId]);

        if ($response->failed() || ! $response->json('ok')) {
            Log::warning('Failed to auto-join a Slack channel after binding it', [
                'channel' => $channelId,
                'error' => $response->json('error') ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
