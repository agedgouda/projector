<?php

namespace App\Jobs;

use App\Models\AiTemplate;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\TextExtractionService;
use App\Services\TaskListImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the AI extraction Slack's 3-second ack window can't wait for, then posts the result back
 * to Slack's `response_url` (valid for ~30 minutes after the command, per Slack's docs) — the
 * same deferred-response pattern Slack expects from any slash command whose work takes real time.
 *
 * Reuses the same extraction call and field-normalization helpers (TextExtractionService,
 * TaskListImportService) that document-based task extraction already uses, rather than a fixed
 * extraction_rule prompt tuned for a single short command instead of a whole document — there's
 * no classify() pass here since a slash command's text is always exactly one task, not a mix of
 * record types to sort out first.
 */
class CreateTaskFromSlackCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Used only if the 'slack_task_extraction' AiTemplate row is ever missing (e.g. a fresh
     * environment before migrations seed it) — the row, editable by super-admins in the
     * Transformation Library, is the normal source of this text.
     */
    private const DEFAULT_EXTRACTION_RULE = <<<'RULE'
        The entire source text is a single task, typed by hand as a short command — not a
        document to search for multiple records. Extract exactly one record:
        - name: a short, clear title for the task (rewrite for clarity if the source is terse).
        - description: the fuller task description, if the source has more detail than fits in
          the title; otherwise the same as name.
        - assignee: if the text names a person responsible for the task and a "Known people"
          list is given below, match them against that list — even from a first name, nickname,
          or partial name — and output that person's full name exactly as listed, only when
          there's one clear, unambiguous match. If no list is given, or no listed person is a
          clear match, output null rather than guessing.
        - due_at: a due date, if one is mentioned (including relative dates like "tomorrow" or
          "Friday" — resolve them to an actual date), else null.
        - priority: "low", "medium", or "high" if urgency language is used (e.g. "urgent",
          "whenever", "ASAP"), else null.
        - tag: a category or label if one is clearly implied, else null.
        RULE;

    /**
     * Slash commands (responseUrl set) and message shortcuts (slackBotToken + slackChannelId
     * set instead) reach this job with different ways to deliver the result — a slash command's
     * response_url is a Slack-generated, single-purpose callback URL, but a shortcut's modal
     * submission has no equivalent, so it has to post back as the bot via chat.postMessage
     * instead. Exactly one of the two delivery modes is ever set — see reply() below.
     */
    public function __construct(
        public Project $project,
        public User $user,
        public string $text,
        public ?string $responseUrl = null,
        public ?string $slackBotToken = null,
        public ?string $slackChannelId = null,
    ) {}

    public function handle(TextExtractionService $extractionService, TaskListImportService $importService): void
    {
        $this->project->loadMissing('client.organization.users', 'client.organization.invitations', 'kanbanColumns');
        $organization = $this->project->client?->organization;
        $organizationId = $organization?->id;

        try {
            $rule = $this->extractionRule();
            if ($organization !== null) {
                $rule .= $this->rosterSection($organization->users, $organization->invitations);
            }

            $result = $extractionService->extract($this->text, 'task', $rule, $organizationId);
            $record = $result['records'][0] ?? null;
        } catch (Throwable $e) {
            Log::warning('Slack /task extraction failed, falling back to raw text', ['message' => $e->getMessage()]);
            $record = null;
        }

        // A failed or empty extraction still creates a task — from the raw text verbatim — rather
        // than leaving the user's /task command with no visible result at all.
        $name = trim($record['name'] ?? '') !== '' ? trim($record['name']) : trim($this->text);

        $priority = $importService->normalizePriority($record['priority'] ?? '');
        $kanbanColumns = $this->project->kanbanColumns;
        $defaultColumn = $kanbanColumns->first();
        $defaultStatus = $defaultColumn !== null ? $defaultColumn->key : 'todo';
        $taskStatus = $importService->normalizeStatus($record['task_status'] ?? '', $kanbanColumns, $defaultStatus);
        $dueAt = $importService->parseDate($record['due_at'] ?? '');
        $assignee = $organization !== null
            ? $importService->resolveAssignee($record['assignee'] ?? null, $organization->users, $organization->invitations)
            : ['assignee_id' => null, 'pending_assignee_invitation_id' => null];

        $tag = null;
        if ($organization !== null && filled($record['tag'] ?? null)) {
            $tag = $importService->findOrCreateTag($record['tag'], $this->project->familyRoot(), $this->project->familyCategories());
        }

        $document = $this->project->documents()->make();
        // See ImportTaskList::importTasks() for why status/task_status are forceFill'd together
        // — the same legacy-column quirk applies here.
        $document->forceFill([
            'type' => 'task',
            'name' => $name,
            'content' => trim($record['description'] ?? '') ?: $name,
            'priority' => $priority,
            'status' => $taskStatus,
            'task_status' => $taskStatus,
            'due_at' => $dueAt,
            'assignee_id' => $assignee['assignee_id'],
            'pending_assignee_invitation_id' => $assignee['pending_assignee_invitation_id'],
            'creator_id' => $this->user->id,
            'metadata' => ['created_from' => 'slack'],
        ]);
        $document->save();

        if ($tag !== null) {
            $document->categories()->sync([$tag->id]);
        }

        $taskUrl = route('projects.documents.show', [$this->project, $document]);

        $this->reply('in_channel', "✅ Created task: <{$taskUrl}|{$name}>");
    }

    /**
     * @see __construct()'s docblock for why there are two delivery modes.
     */
    private function reply(string $responseType, string $text): void
    {
        if ($this->responseUrl !== null) {
            Http::post($this->responseUrl, ['response_type' => $responseType, 'text' => $text]);

            return;
        }

        if ($this->slackBotToken !== null && $this->slackChannelId !== null) {
            $response = Http::withToken($this->slackBotToken)->post('https://slack.com/api/chat.postMessage', [
                'channel' => $this->slackChannelId,
                'text' => $text,
            ]);

            // chat.postMessage returns HTTP 200 even on failure (e.g. the bot isn't a member of
            // the channel) — Slack puts the real result in the JSON body's `ok`/`error` fields.
            if ($response->json('ok') !== true) {
                Log::warning('Slack chat.postMessage failed for a task created via message shortcut', [
                    'channel' => $this->slackChannelId,
                    'error' => $response->json('error'),
                ]);
            }
        }
    }

    /**
     * The 'slack_task_extraction' AiTemplate's user_prompt *is* the extraction_rule text (see
     * the migration that seeds it) — editable by super-admins in the Transformation Library,
     * rather than the fixed PHP constant this replaced.
     */
    private function extractionRule(): string
    {
        $rule = AiTemplate::where('type', 'slack_task_extraction')->value('user_prompt');

        return is_string($rule) && trim($rule) !== '' ? $rule : self::DEFAULT_EXTRACTION_RULE;
    }

    /**
     * Appended to the extraction rule so the AI can resolve a first name, nickname, or partial
     * name (e.g. "Penny") against the project's actual org members — matching by exact full name
     * is all resolveAssignee() ever does downstream, so the AI has to be the one to bridge "who
     * does this text mean" to "their exact full name" rather than that exact-match code guessing.
     * The two name sources mirror exactly what resolveAssignee() itself checks, so the AI never
     * offers a name that code then fails to match.
     *
     * $users and $invitations are deliberately untyped generics (matching resolveAssignee()'s
     * own bare `Collection` parameters) — callers pass Organization::$users (a UserCollection)
     * and Organization::$invitations, not a plain Collection.
     */
    private function rosterSection(Collection $users, Collection $invitations): string
    {
        $names = [];

        foreach ($users as $user) {
            /** @var User $user */
            $names[] = trim((string) $user->name);
        }

        foreach ($invitations as $invitation) {
            /** @var OrganizationInvitation $invitation */
            $names[] = trim("{$invitation->first_name} {$invitation->last_name}");
        }

        $names = collect($names)->filter(fn (string $name) => $name !== '')->unique()->values();

        if ($names->isEmpty()) {
            return '';
        }

        return "\n\nKnown people who can be assigned tasks on this project:\n"
            .$names->map(fn (string $name) => "- {$name}")->implode("\n");
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CreateTaskFromSlackCommand failed: '.$exception->getMessage());

        $this->reply('ephemeral', "Sorry, something went wrong creating that task: {$exception->getMessage()}");
    }
}
