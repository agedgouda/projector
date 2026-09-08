<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\ImportSlackFile;
use App\Models\SlackChannelBinding;
use App\Models\SlackUserIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EventsController extends Controller
{
    /**
     * @var list<string>
     */
    private const IMPORTABLE_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * Handles Slack's Events API callbacks. `VerifySlackSignature` (applied at the route level)
     * has already authenticated the request before this runs.
     *
     * Slack requires this endpoint to answer the one-time `url_verification` handshake before it
     * will let an admin save a Request URL in the app config.
     */
    public function handle(Request $request): JsonResponse|Response
    {
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        $eventType = $request->input('event.type');
        $subtype = $request->input('event.subtype');

        // Subscribed to message.channels (not the older, less reliable file_shared event) —
        // a file dropped into a channel arrives as a normal message event with this subtype and
        // a `files` array already carrying everything needed to import it, no files.info call
        // required. Every other message in every bound channel also arrives here since that's
        // the coarsest subscription Slack offers; anything that isn't this exact shape is
        // ignored below rather than acted on.
        if ($eventType === 'message' && $subtype === 'file_share') {
            $this->handleFileShared($request);
        } else {
            Log::info('Received unhandled Slack event', ['type' => $request->input('type'), 'event_type' => $eventType]);
        }

        return response()->noContent();
    }

    /**
     * Imports the first spreadsheet-like file in a file_share message as tasks and/or events
     * (ImportSlackFile classifies which), silently ignoring anything that doesn't have a clear
     * enough destination to act on (an unbound channel, a non-spreadsheet file) — the same
     * restraint /task and /events use for text that doesn't look like a command, rather than
     * commenting on every file anyone ever shares.
     * Unlike a slash command or shortcut, there's no interactive context to reply ephemerally
     * through here, so the one case worth telling someone about (an unlinked Slack identity)
     * has to go in-channel instead — CommandsController/InteractivityController's ephemeral
     * equivalent, just visible to everyone since that's the only channel available.
     */
    private function handleFileShared(Request $request): void
    {
        $teamId = $request->input('team_id');
        $channelId = $request->input('event.channel');
        $slackUserId = $request->input('event.user');
        $files = $request->input('event.files', []);

        if (! is_string($teamId) || ! is_string($channelId) || ! is_string($slackUserId) || ! is_array($files) || $files === []) {
            return;
        }

        $file = $files[0];
        $name = is_array($file) ? ($file['name'] ?? null) : null;
        $urlPrivateDownload = is_array($file) ? ($file['url_private_download'] ?? null) : null;
        $mimetypeRaw = is_array($file) ? ($file['mimetype'] ?? null) : null;
        $mimetype = is_string($mimetypeRaw) ? $mimetypeRaw : null;

        if (! is_string($name) || ! is_string($urlPrivateDownload)) {
            return;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, self::IMPORTABLE_EXTENSIONS, true)) {
            return;
        }

        $binding = SlackChannelBinding::whereHas('slackWorkspace', fn ($query) => $query->where('team_id', $teamId))
            ->where('channel_id', $channelId)
            ->with('project')
            ->first();

        if ($binding === null) {
            return;
        }

        $identity = SlackUserIdentity::where('slack_team_id', $teamId)
            ->where('slack_user_id', $slackUserId)
            ->with('user')
            ->first();

        $workspace = $binding->slackWorkspace;

        if ($identity === null) {
            $connectUrl = route('integrations.edit');
            $this->postToChannel($workspace->bot_access_token, $channelId, "Connect your Slack account in Projector first, so files you import are attributed to you: {$connectUrl}");

            return;
        }

        ImportSlackFile::dispatch(
            $binding->project,
            $identity->user,
            ['name' => $name, 'url_private_download' => $urlPrivateDownload, 'mimetype' => $mimetype],
            $workspace->bot_access_token,
            $channelId,
        );
    }

    private function postToChannel(string $botToken, string $channelId, string $text): void
    {
        Http::withToken($botToken)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $channelId,
            'text' => $text,
        ]);
    }
}
