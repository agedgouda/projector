<?php

namespace App\Services\Import;

use App\Mail\ImportResultMail;
use App\Models\Project;
use App\Models\SlackUserIdentity;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a FileImportProcessor outcome message however makes sense for the source it came
 * from. A source with a real-time channel to reply into (Slack, so far) passes one via
 * $slackChannelReply and gets an in-channel post, exactly like ImportSlackFile always has. A
 * source with no such channel (Dropbox, so far — a folder isn't a place to post a reply) instead
 * reaches the attributed user directly: a Slack DM if they have a SlackUserIdentity linked for
 * the project's organization, otherwise an email — so a Dropbox-triggered import can still end
 * up delivered *through* Slack, just not in a channel.
 */
class ImportNotifier
{
    /**
     * @param  array{bot_token: string, channel_id: string}|null  $slackChannelReply
     */
    public function notify(User $recipient, string $message, Project $project, ?array $slackChannelReply = null): void
    {
        if ($slackChannelReply !== null) {
            $this->postToSlack($slackChannelReply['bot_token'], $slackChannelReply['channel_id'], $message);

            return;
        }

        $workspace = $project->organization_id !== null
            ? SlackWorkspace::where('organization_id', $project->organization_id)->first()
            : null;

        $identity = $workspace !== null
            ? SlackUserIdentity::where('user_id', $recipient->id)->where('slack_team_id', $workspace->team_id)->first()
            : null;

        if ($workspace !== null && $identity !== null) {
            $this->postToSlack($workspace->bot_access_token, $identity->slack_user_id, $message);

            return;
        }

        Mail::to($recipient)->send(new ImportResultMail($message));
    }

    private function postToSlack(string $botToken, string $channelOrUserId, string $text): void
    {
        $response = Http::withToken($botToken)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $channelOrUserId,
            'text' => $text,
        ]);

        if ($response->json('ok') !== true) {
            Log::warning('Slack chat.postMessage failed for an import notification', [
                'channel' => $channelOrUserId,
                'error' => $response->json('error'),
            ]);
        }
    }
}
