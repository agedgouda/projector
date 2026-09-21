<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Services\Reports\CalendarExportBuilder;
use App\Services\Reports\ReportRequestParser;
use App\Services\Reports\TaskReportBuilder;
use App\Services\Slack\SlackFileUploader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads what the person asked for in plain English (ReportRequestParser), builds the report for
 * it — a task report (the Reports tab's downloads, see TaskReportBuilder) when the request says
 * "tasks" or names neither kind, an event calendar (the Campaign Calendar's downloads, see
 * CalendarExportBuilder) when it says "events", both when it says both — and uploads each into
 * the channel `/report` was run in.
 * Deferred to a queue since the AI call, generating a file, and uploading it can't finish inside
 * Slack's 3-second ack window; anything that goes wrong is reported back ephemerally through the
 * command's `response_url`.
 */
class GenerateReportFromSlackCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public Project $project,
        public User $user,
        public SlackWorkspace $workspace,
        public string $channelId,
        public string $text,
        public string $responseUrl,
    ) {}

    public function handle(TaskReportBuilder $tasks, CalendarExportBuilder $calendars, SlackFileUploader $uploader, ReportRequestParser $parser): void
    {
        try {
            $request = $parser->parse($this->text, $this->project, $this->user);
        } catch (Throwable $e) {
            Log::warning('Slack /report could not be interpreted', ['message' => $e->getMessage()]);

            // Deliberately not falling back to the unfiltered report: it would look like an answer
            // to the request while ignoring everything the person actually asked for.
            $this->reply("Sorry, I couldn't work out what report you meant — try rephrasing, or use `/report` on its own for the full task report.");

            return;
        }

        if ($request['unresolved'] !== []) {
            $this->reply("I couldn't find ".implode(', ', $request['unresolved']).' for this project, so I didn\'t generate a report. Check the spelling and try again.');

            return;
        }

        $wantsEvents = preg_match('/\bevents?\b/i', $this->text) === 1;
        $wantsTasks = preg_match('/\btasks?\b/i', $this->text) === 1 || ! $wantsEvents;

        if ($wantsEvents && ! $wantsTasks && ($unsupported = $this->filtersEventsCannotUse($request['filters'])) !== []) {
            $this->reply('An event calendar can only be filtered by tag, sub-project, and dates, so I didn\'t generate one — I can\'t filter events by '.implode(', ', $unsupported).'. Ask for tasks to filter by those.');

            return;
        }

        if ($wantsTasks) {
            $this->sendTaskReport($tasks, $uploader, $request);
        }

        if ($wantsEvents) {
            $this->sendEventCalendar($calendars, $uploader, $request);
        }
    }

    /**
     * @param  array{format: 'xlsx'|'csv'|'pdf'|null, filters: array<string, mixed>, summary: array<string, string>, unresolved: list<string>}  $request
     */
    private function sendTaskReport(TaskReportBuilder $builder, SlackFileUploader $uploader, array $request): void
    {
        [$tasks, $includeDetails, $projectNames, $mode] = $builder->tasksForExport($request['filters'], $this->project);

        if ($tasks->isEmpty()) {
            $this->reply($request['summary'] === []
                ? "There are no tasks in {$this->project->name} to report on."
                : 'No tasks matched: '.implode(' · ', $request['summary']));

            return;
        }

        $format = $request['format'] ?? 'xlsx';

        $contents = match ($format) {
            'csv' => $builder->csvContents($this->project, $tasks, $includeDetails, $projectNames, $mode),
            'pdf' => $builder->pdfContents($this->project, $tasks, $includeDetails, $projectNames, $mode),
            'xlsx' => $builder->excelContents($this->project, $tasks, $includeDetails, $projectNames, $mode),
        };

        $comment = "📊 Task report for *{$this->project->name}* — requested by {$this->user->name}";
        if ($request['summary'] !== []) {
            $comment .= "\nFilters: ".implode(' · ', $request['summary']);
        }

        $uploader->upload($this->workspace, $this->channelId, $builder->filename($this->project, $format), $contents, $comment);
    }

    /**
     * The calendar has no assignees, statuses, or priorities to filter on, and no notion of a
     * "done" date — only what the Campaign Calendar tab itself can filter by (tags and
     * sub-projects), plus a date window.
     *
     * @param  array{format: 'xlsx'|'csv'|'pdf'|null, filters: array<string, mixed>, summary: array<string, string>, unresolved: list<string>}  $request
     */
    private function sendEventCalendar(CalendarExportBuilder $builder, SlackFileUploader $uploader, array $request): void
    {
        $filters = $request['filters'];
        $summary = array_intersect_key($request['summary'], array_flip(['tag', 'project', 'dates']));

        $items = $builder->items(
            $this->project,
            tags: $this->stringList($filters['category_id'] ?? null),
            showTasks: false,
            showEvents: true,
            from: $this->date($filters['due_from'] ?? null),
            to: $this->date($filters['due_to'] ?? null),
            onlyProjects: $this->stringList($filters['project_id'] ?? null),
        );

        if ($builder->excludeUndatedItems($items, $builder->usesExternalDueDates($this->project))->isEmpty()) {
            $this->reply($summary === []
                ? "There are no upcoming events in {$this->project->name} to put on a calendar."
                : 'No events matched: '.implode(' · ', $summary));

            return;
        }

        $format = $request['format'] ?? 'pdf';

        $contents = match ($format) {
            'csv' => $builder->csvContents($this->project, $items),
            'xlsx' => $builder->excelContents($this->project, $items),
            'pdf' => $builder->pdfContents($this->project, $items),
        };

        $comment = "📅 Event calendar for *{$this->project->name}* — requested by {$this->user->name}";
        if ($summary !== []) {
            $comment .= "\nFilters: ".implode(' · ', $summary);
        }

        $uploader->upload($this->workspace, $this->channelId, $builder->filename($this->project, $format), $contents, $comment);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function filtersEventsCannotUse(array $filters): array
    {
        $unsupported = [];

        foreach (['assignee' => 'assignee', 'task_status' => 'status', 'priority' => 'priority'] as $key => $label) {
            if (! empty($filters[$key])) {
                $unsupported[] = $label;
            }
        }

        if (($filters['mode'] ?? null) === 'done') {
            $unsupported[] = 'completion date';
        }

        return $unsupported;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateReportFromSlackCommand failed: '.$exception->getMessage(), [
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $this->reply($this->failureMessage($exception));
    }

    /**
     * Slack's own error codes are the useful part of an upload failure — two of them have a
     * specific fix the person who ran the command can pass along.
     */
    private function failureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'missing_scope')) {
            return "Sorry, Projector can't upload files to Slack yet. An org-admin needs to reconnect Slack from the organization's Configuration tab to grant the file-upload permission.";
        }

        if (str_contains($message, 'not_in_channel') || str_contains($message, 'channel_not_found')) {
            return 'Sorry, the Projector bot has to be in this channel to post a report — invite it with `/invite @Projector`, then try again.';
        }

        return "Sorry, something went wrong generating that report: {$message}";
    }

    private function reply(string $text): void
    {
        Http::post($this->responseUrl, ['response_type' => 'ephemeral', 'text' => $text]);
    }
}
