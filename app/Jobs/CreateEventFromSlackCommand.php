<?php

namespace App\Jobs;

use App\Models\AiTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\TextExtractionService;
use App\Services\TaskListImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The /events counterpart to CreateTaskFromSlackCommand — same deferred-response shape (Slack's
 * 3s ack window can't wait for the AI call), same extraction service, but no assignee at all:
 * events aren't assigned to people, only tagged, so there's no roster to build or resolveAssignee()
 * call here — the one thing tasks and events do share, tag resolution, is reused as-is.
 */
class CreateEventFromSlackCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Used only if the 'slack_event_extraction' AiTemplate row is ever missing (e.g. a fresh
     * environment before migrations seed it) — the row, editable by super-admins in the
     * Transformation Library, is the normal source of this text.
     */
    private const DEFAULT_EXTRACTION_RULE = <<<'RULE'
        The entire source text is a single event, typed by hand as a short command — not a
        document to search for multiple records. Extract exactly one record:
        - name: a short, clear title for the event (rewrite for clarity if the source is terse).
        - description: the fuller event description, if the source has more detail than fits in
          the title; otherwise the same as name.
        - start_date and due_at: the event's date(s), as YYYY-MM-DD (including relative dates
          like "tomorrow" or "next Thursday" — resolve them to an actual date). If the source
          gives only one date, use it for due_at and leave start_date null — the caller fills in
          the other end of the range itself when that happens. Null both if no date is mentioned.
        - tag: a category or label for the event if one is clearly implied (e.g. "team offsite",
          "client meeting", "deadline"), else null.
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
        $organizationId = $this->project->client?->organization_id;

        try {
            $result = $extractionService->extract($this->text, 'event', $this->extractionRule(), $organizationId);
            $record = $result['records'][0] ?? null;
        } catch (Throwable $e) {
            Log::warning('Slack /events extraction failed, falling back to raw text', ['message' => $e->getMessage()]);
            $record = null;
        }

        // A failed or empty extraction still creates an event — from the raw text verbatim —
        // rather than leaving the user's /events command with no visible result at all.
        $name = trim($record['name'] ?? '') !== '' ? trim($record['name']) : trim($this->text);
        $description = trim($record['description'] ?? '') ?: $name;

        $startAt = $importService->parseDate($record['start_date'] ?? '');
        $dueAt = $importService->parseDate($record['due_at'] ?? '');

        // Same one-day-event rule as ExtractTextRecords::createEvents() and ImportTaskList::
        // importEvents() — a record with only one date gets that same date for both ends.
        if ($startAt !== null && $dueAt === null) {
            $dueAt = $startAt;
        } elseif ($dueAt !== null && $startAt === null) {
            $startAt = $dueAt;
        }

        $tag = null;
        if (filled($record['tag'] ?? null)) {
            $tag = $importService->findOrCreateTag($record['tag'], $this->project->familyRoot(), $this->project->familyCategories());
        }

        $document = $this->project->documents()->create([
            'type' => 'event',
            'name' => $name,
            'content' => $description,
            'start_at' => $startAt,
            'due_at' => $dueAt,
            'creator_id' => $this->user->id,
            'metadata' => ['created_from' => 'slack'],
        ]);

        if ($tag !== null) {
            $document->categories()->sync([$tag->id]);
        }

        $eventUrl = route('projects.documents.show', [$this->project, $document]);

        $this->reply('in_channel', "✅ Created event: <{$eventUrl}|{$name}>");
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
                Log::warning('Slack chat.postMessage failed for an event created via message shortcut', [
                    'channel' => $this->slackChannelId,
                    'error' => $response->json('error'),
                ]);
            }
        }
    }

    /**
     * The 'slack_event_extraction' AiTemplate's user_prompt *is* the extraction_rule text —
     * editable by super-admins in the Transformation Library, rather than a fixed PHP constant.
     */
    private function extractionRule(): string
    {
        $rule = AiTemplate::where('type', 'slack_event_extraction')->value('user_prompt');

        return is_string($rule) && trim($rule) !== '' ? $rule : self::DEFAULT_EXTRACTION_RULE;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CreateEventFromSlackCommand failed: '.$exception->getMessage());

        $this->reply('ephemeral', "Sorry, something went wrong creating that event: {$exception->getMessage()}");
    }
}
