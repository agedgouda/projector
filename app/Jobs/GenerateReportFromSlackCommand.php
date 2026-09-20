<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Services\Reports\ReportRequestParser;
use App\Services\Reports\TaskReportBuilder;
use App\Services\Slack\SlackFileUploader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads what the person asked for in plain English (ReportRequestParser), builds the project's
 * task report for it — the same rows, sorting, and file layout the Reports tab's own downloads
 * produce (see TaskReportBuilder) — and uploads it into the channel `/report` was run in.
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

    public function handle(TaskReportBuilder $builder, SlackFileUploader $uploader, ReportRequestParser $parser): void
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

        [$tasks, $includeDetails, $projectNames, $mode] = $builder->tasksForExport($request['filters'], $this->project);

        if ($tasks->isEmpty()) {
            $this->reply($request['summary'] === []
                ? "There are no tasks in {$this->project->name} to report on."
                : 'No tasks matched: '.implode(' · ', $request['summary']));

            return;
        }

        $format = $request['format'];

        $contents = match ($format) {
            'csv' => $builder->csvContents($this->project, $tasks, $includeDetails, $projectNames, $mode),
            'pdf' => $builder->pdfContents($this->project, $tasks, $includeDetails, $projectNames, $mode),
            'xlsx' => $builder->excelContents($this->project, $tasks, $includeDetails, $projectNames, $mode),
        };

        $comment = "📊 Task report for *{$this->project->name}* — requested by {$this->user->name}";
        if ($request['summary'] !== []) {
            $comment .= "\nFilters: ".implode(' · ', $request['summary']);
        }

        $uploader->upload(
            $this->workspace,
            $this->channelId,
            $builder->filename($this->project, $format),
            $contents,
            $comment,
        );
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
