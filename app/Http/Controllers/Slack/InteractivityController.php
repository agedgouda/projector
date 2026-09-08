<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\CreateEventFromSlackCommand;
use App\Jobs\CreateTaskFromSlackCommand;
use App\Models\SlackChannelBinding;
use App\Models\SlackUserIdentity;
use App\Models\SlackWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class InteractivityController extends Controller
{
    /**
     * callback_id => [command, noun, title] for the two message shortcuts. Mirrors
     * CommandsController::COMMANDS but keyed by callback_id (a shortcut identifier) rather than
     * a typed command string, since that's what Slack's shortcut payload actually carries.
     *
     * @var array<string, array{command: string, noun: string, title: string}>
     */
    private const SHORTCUTS = [
        'create_task' => ['command' => '/task', 'noun' => 'task', 'title' => 'Create Task'],
        'create_event' => ['command' => '/events', 'noun' => 'event', 'title' => 'Create Event'],
    ];

    /**
     * Handles Slack's Interactivity payloads (message shortcuts and the modals they open).
     * VerifySlackSignature (applied at the route level) has already authenticated the request.
     *
     * Unlike the Events API and slash commands, Slack sends this as a single `payload` form
     * field containing JSON, not the request body directly or flat fields.
     */
    public function handle(Request $request): Response
    {
        $raw = $request->input('payload');
        $payload = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($payload)) {
            return $this->ack();
        }

        return match ($payload['type'] ?? null) {
            'message_action' => $this->handleShortcut($payload),
            'view_submission' => $this->handleViewSubmission($payload),
            default => $this->ack(),
        };
    }

    /**
     * Slack's client treats a `view_submission` response of `{}` or `[]` as malformed — surfacing
     * "We had some trouble connecting" even though (as here) the request was otherwise handled
     * successfully — and expects a genuinely empty body to just close the modal. The same empty
     * ack is fine for message-shortcut and unrecognized-payload responses too, since those never
     * need `response_action`-shaped JSON either.
     */
    private function ack(): Response
    {
        return response('', 200);
    }

    /**
     * A message shortcut ("More actions" on a message) has to respond within Slack's 3-second
     * window by opening a modal via views.open using the one-time `trigger_id` — there's no room
     * to defer this part to a queued job the way the AI extraction itself is deferred. The modal
     * either shows an editable copy of the message text (channel bound, identity linked) or an
     * explanation of what's missing, so this never silently does nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleShortcut(array $payload): Response
    {
        $callbackId = data_get($payload, 'callback_id');
        $shortcut = is_string($callbackId) ? (self::SHORTCUTS[$callbackId] ?? null) : null;
        $teamId = data_get($payload, 'team.id');
        $channelId = data_get($payload, 'channel.id');
        $slackUserId = data_get($payload, 'user.id');
        $triggerId = data_get($payload, 'trigger_id');
        $messageText = data_get($payload, 'message.text', '');

        if ($shortcut === null || ! is_string($teamId) || ! is_string($channelId) || ! is_string($slackUserId) || ! is_string($triggerId) || ! is_string($messageText)) {
            return $this->ack();
        }

        $binding = $this->resolveBinding($teamId, $channelId);
        $identity = $this->resolveIdentity($teamId, $slackUserId);

        // More than one organization can share the same real Slack team (see
        // drop_team_id_unique_from_slack_workspaces_table), so the binding — which points at
        // one specific organization's own project — is what the bot token has to come from
        // when it's available, rather than an arbitrary same-team workspace row. Only the
        // "channel isn't bound to anything yet" case has no binding to resolve it from; any
        // workspace row for this team still opens the (generic, not org-specific) error modal.
        $workspace = $binding !== null
            ? $binding->slackWorkspace
            : SlackWorkspace::where('team_id', $teamId)->first();

        // Can't open any modal at all without a bot token to call views.open with.
        if ($workspace === null) {
            return $this->ack();
        }

        if ($binding === null) {
            $view = $this->errorView($shortcut['title'], "This channel isn't bound to a project yet — an org-admin can bind it from the organization's Configuration tab in Projector.");
        } elseif ($identity === null) {
            $connectUrl = route('integrations.edit');
            $view = $this->errorView($shortcut['title'], "Connect your Slack account in Projector first, so {$shortcut['noun']}s you create are attributed to you: {$connectUrl}");
        } else {
            $view = $this->editView($shortcut['command'], $shortcut['title'], $teamId, $channelId, $slackUserId, $messageText);
        }

        Http::withToken($workspace->bot_access_token)->post('https://slack.com/api/views.open', [
            'trigger_id' => $triggerId,
            'view' => $view,
        ]);

        return $this->ack();
    }

    /**
     * The edit modal's submission. Re-resolves the channel binding/identity from private_metadata
     * rather than trusting anything client-supplied beyond the edited text — the same "channel
     * unbound"/"identity unlinked" checks apply here too, in case either changed between the
     * shortcut being invoked and the modal being submitted; if so this silently drops the
     * submission (closing the modal) rather than erroring, since there's no good place left to
     * show an error message once the modal is already closing.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleViewSubmission(array $payload): Response
    {
        $metadataRaw = data_get($payload, 'view.private_metadata');
        $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : null;

        if (! is_array($metadata)) {
            return $this->ack();
        }

        $command = data_get($metadata, 'command');
        $teamId = data_get($metadata, 'team_id');
        $channelId = data_get($metadata, 'channel_id');
        $slackUserId = data_get($metadata, 'slack_user_id');
        $text = data_get($payload, 'view.state.values.description_block.description_input.value');

        if (! is_string($command) || ! is_string($teamId) || ! is_string($channelId) || ! is_string($slackUserId) || ! is_string($text) || trim($text) === '') {
            return $this->ack();
        }

        $binding = $this->resolveBinding($teamId, $channelId);
        $identity = $this->resolveIdentity($teamId, $slackUserId);

        if ($binding === null || $identity === null) {
            return $this->ack();
        }

        // Comes from the binding (this specific organization's own workspace row), not a
        // team_id-only lookup — see handleShortcut()'s comment for why that matters once more
        // than one organization can share the same real Slack team. slack_workspace_id is a
        // non-nullable, cascade-deleting foreign key, so a binding can't outlive its workspace.
        $workspace = $binding->slackWorkspace;

        if ($command === '/task') {
            CreateTaskFromSlackCommand::dispatch($binding->project, $identity->user, trim($text), null, $workspace->bot_access_token, $channelId);
        } else {
            CreateEventFromSlackCommand::dispatch($binding->project, $identity->user, trim($text), null, $workspace->bot_access_token, $channelId);
        }

        return $this->ack();
    }

    private function resolveBinding(string $teamId, string $channelId): ?SlackChannelBinding
    {
        return SlackChannelBinding::whereHas('slackWorkspace', fn ($query) => $query->where('team_id', $teamId))
            ->where('channel_id', $channelId)
            ->with('project')
            ->first();
    }

    private function resolveIdentity(string $teamId, string $slackUserId): ?SlackUserIdentity
    {
        return SlackUserIdentity::where('slack_team_id', $teamId)
            ->where('slack_user_id', $slackUserId)
            ->with('user')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function editView(string $command, string $title, string $teamId, string $channelId, string $slackUserId, string $messageText): array
    {
        return [
            'type' => 'modal',
            'private_metadata' => json_encode([
                'command' => $command,
                'team_id' => $teamId,
                'channel_id' => $channelId,
                'slack_user_id' => $slackUserId,
            ]),
            'title' => ['type' => 'plain_text', 'text' => $title],
            'submit' => ['type' => 'plain_text', 'text' => 'Create'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'description_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Description'],
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'description_input',
                        'multiline' => true,
                        'initial_value' => $messageText,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorView(string $title, string $text): array
    {
        return [
            'type' => 'modal',
            'title' => ['type' => 'plain_text', 'text' => $title],
            'close' => ['type' => 'plain_text', 'text' => 'OK'],
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
            ],
        ];
    }
}
