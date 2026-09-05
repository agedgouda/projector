<?php

namespace App\Services\Slack;

use App\Models\SlackWorkspace;
use Illuminate\Support\Facades\Http;

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
}
