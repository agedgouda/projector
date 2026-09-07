<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\CreateEventFromSlackCommand;
use App\Jobs\CreateTaskFromSlackCommand;
use App\Models\SlackChannelBinding;
use App\Models\SlackUserIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandsController extends Controller
{
    /**
     * Command name => [usage example, noun used in messages]. Both commands share every step
     * except which job they dispatch — the channel/identity resolution and validation are
     * identical, so this is the only thing that varies by command.
     *
     * @var array<string, array{example: string, noun: string}>
     */
    private const COMMANDS = [
        '/task' => ['example' => 'follow up with the client about the contract by Friday', 'noun' => 'task'],
        '/events' => ['example' => 'team offsite next Thursday', 'noun' => 'event'],
    ];

    /**
     * Handles Slack slash commands. VerifySlackSignature (applied at the route level) has
     * already authenticated the request before this runs.
     *
     * Every response here is an immediate, synchronous ack — Slack requires one within 3
     * seconds. The channel/identity lookups are fast enough to do inline; only the AI extraction
     * (CreateTaskFromSlackCommand/CreateEventFromSlackCommand) is slow enough to need deferring
     * to a queued job that posts its result to `response_url` afterward.
     */
    public function handle(Request $request): JsonResponse
    {
        $command = $request->input('command');

        if (! is_string($command) || ! isset(self::COMMANDS[$command])) {
            return $this->ephemeral('Unknown command.');
        }

        ['example' => $example, 'noun' => $noun] = self::COMMANDS[$command];

        $text = $request->input('text');
        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            return $this->ephemeral("Usage: `{$command} <description>` — e.g. `{$command} {$example}`.");
        }

        $teamId = $request->input('team_id');
        $channelId = $request->input('channel_id');
        $slackUserId = $request->input('user_id');
        $responseUrl = $request->input('response_url');

        if (! is_string($teamId) || ! is_string($channelId) || ! is_string($slackUserId) || ! is_string($responseUrl)) {
            return $this->ephemeral('Something is missing from that request — please try again.');
        }

        $binding = SlackChannelBinding::whereHas('slackWorkspace', fn ($query) => $query->where('team_id', $teamId))
            ->where('channel_id', $channelId)
            ->with('project')
            ->first();

        if ($binding === null) {
            return $this->ephemeral("This channel isn't bound to a project yet — an org-admin can bind it from the organization's Configuration tab in Projector.");
        }

        $identity = SlackUserIdentity::where('slack_team_id', $teamId)
            ->where('slack_user_id', $slackUserId)
            ->with('user')
            ->first();

        if ($identity === null) {
            $connectUrl = route('integrations.edit');

            return $this->ephemeral("Connect your Slack account in Projector first, so {$noun}s you create are attributed to you: {$connectUrl}");
        }

        if ($command === '/task') {
            CreateTaskFromSlackCommand::dispatch($binding->project, $identity->user, $text, $responseUrl);
        } else {
            CreateEventFromSlackCommand::dispatch($binding->project, $identity->user, $text, $responseUrl);
        }

        return $this->ephemeral("⏳ Creating {$noun}…");
    }

    private function ephemeral(string $text): JsonResponse
    {
        return response()->json(['response_type' => 'ephemeral', 'text' => $text]);
    }
}
