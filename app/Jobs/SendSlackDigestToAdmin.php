<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\SlackUserIdentity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Slack DM for one (organization, org-admin) pair — SendSlackDailyDigest (the scheduled
 * command) is what decides *when* an admin is due a digest and records that decision (via
 * SlackDigestSend, for once-per-local-day idempotency) before dispatching this; this job only
 * builds the content and delivers it, the same division of responsibility CommandsController/
 * CreateTaskFromSlackCommand already use for the other Slack jobs.
 */
class SendSlackDigestToAdmin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * How many upcoming deliverables to list when nothing is due today.
     */
    private const FALLBACK_LIMIT = 5;

    public function __construct(
        public Organization $organization,
        public User $admin,
    ) {}

    public function handle(): void
    {
        $workspace = $this->organization->slackWorkspace;

        if ($workspace === null) {
            return;
        }

        // Re-resolved here rather than trusted from the command's own lookup — the identity
        // could have been unlinked in the time between the command deciding to dispatch this
        // job and it actually running, the same defensive re-check InteractivityController's
        // view_submission handler does for its channel binding/identity.
        $identity = SlackUserIdentity::where('user_id', $this->admin->id)
            ->where('slack_team_id', $workspace->team_id)
            ->first();

        if ($identity === null) {
            return;
        }

        $projectIds = $this->organization->clients()
            ->join('projects', 'projects.client_id', '=', 'clients.id')
            ->pluck('projects.id');

        if ($projectIds->isEmpty()) {
            return;
        }

        $taskTypes = DocumentTypeDefinition::catalogForOrganization($this->organization->id)
            ->filter(fn (DocumentTypeDefinition $definition) => $definition->is_task)
            ->keys();

        if ($taskTypes->isEmpty()) {
            return;
        }

        $timezone = $this->admin->effectiveTimezone();
        $todayStart = Carbon::now($timezone)->startOfDay()->utc();
        $todayEnd = Carbon::now($timezone)->endOfDay()->utc();

        $baseQuery = fn (): Builder => Document::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('type', $taskTypes)
            ->where('task_status', '!=', 'done')
            ->with('project:id,name');

        $dueToday = $baseQuery()
            ->whereBetween('due_at', [$todayStart, $todayEnd])
            ->orderBy('due_at')
            ->get();

        $lines = $dueToday->isNotEmpty()
            ? $this->dueTodayLines($dueToday)
            : $this->fallbackLines($baseQuery());

        if ($lines === []) {
            // Nothing due today and nothing upcoming at all — no useful digest to send.
            return;
        }

        $header = $dueToday->isNotEmpty()
            ? "📋 *{$this->organization->name} — tasks due today*"
            : '📋 *'.$this->organization->name.' — nothing due today. Next '.count($lines).' deliverables:*';

        $text = $header."\n".implode("\n", $lines);

        $response = Http::withToken($workspace->bot_access_token)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $identity->slack_user_id,
            'text' => $text,
        ]);

        if ($response->json('ok') !== true) {
            Log::warning('Slack chat.postMessage failed for the daily digest', [
                'organization_id' => $this->organization->id,
                'user_id' => $this->admin->id,
                'error' => $response->json('error'),
            ]);
        }
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return array<int, string>
     */
    private function dueTodayLines(Collection $documents): array
    {
        return $documents
            ->map(fn (Document $document) => '• '.$this->taskLink($document).' — '.($document->project->name ?? 'Unknown project'))
            ->all();
    }

    /**
     * @param  Builder<Document>  $query
     * @return array<int, string>
     */
    private function fallbackLines(Builder $query): array
    {
        $upcoming = $query
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->limit(self::FALLBACK_LIMIT)
            ->get();

        return $upcoming->map(function (Document $document) {
            $dueLabel = Carbon::parse($document->due_at)->format('M j');
            $project = $document->project->name ?? 'Unknown project';

            return "• {$this->taskLink($document)} — {$project} (due {$dueLabel})";
        })->all();
    }

    private function taskLink(Document $document): string
    {
        $url = route('projects.documents.show', [$document->project, $document]);

        return "<{$url}|{$document->name}>";
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendSlackDigestToAdmin failed: '.$exception->getMessage(), [
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
        ]);
    }
}
