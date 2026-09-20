<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsTaskFields;
use App\Models\Document;
use App\Models\Project;
use App\Models\ReportFilterPreference;
use App\Services\Google\GoogleExportService;
use App\Services\Reports\TaskReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use FormatsTaskFields;

    /**
     * Search this project's tasks (documents whose type is flagged is_task in the
     * organization's document type catalog) by assignee, status, priority, and due-date
     * range — the first report in what's meant to grow into a broader reporting system,
     * so the filtering/response shape here is deliberately generic rather than tied to
     * any one UI.
     */
    public function projectTasks(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $filters = $request->validate($this->filterRules());
        $query = $this->reports()->buildTasksQuery($filters, $project);

        $tasks = $query?->get([
            'id', 'project_id', 'name', 'due_at', 'external_due_at', 'status_changed_at',
            'priority', 'task_status', 'assignee_id', 'pending_assignee_invitation_id',
            'content', 'type', 'custom_prompt', 'locked_project_type_id',
            'last_ai_template_id', 'processed_at', 'updated_at',
        ]);

        // Needed for the detail sheet's Reprocess-availability check, same as
        // DocumentController::show()'s own loadExists() call — a locked document only has
        // something left to (re)process if its locked protocol still defines a next step.
        // Done here rather than folded into the `?? collect()` below so $tasks stays a real
        // Eloquent collection (loadExists() isn't defined on the base Support\Collection
        // collect() would otherwise widen it to).
        if ($tasks) {
            $tasks->loadExists('lockedNextWorkflowStep');
            $tasks->load('comments.user');
        } else {
            $tasks = collect();
        }

        $projectNames = $this->reports()->projectNamesMap($project);

        return response()->json($tasks->map(fn (Document $task) => $this->taskToArray($task, $projectNames)));
    }

    /**
     * The current user's last-saved task-report filters for this project, if any. Lets the
     * Reports tab remember your selections across browsers/devices, not just within one —
     * browser-local storage (also used client-side for the same-tab-reload/back-forward case)
     * can never share state across two entirely separate browsers or a private window.
     */
    public function taskFilterPreferences(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $preference = ReportFilterPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('project_id', $project->id)
            ->first();

        return response()->json(['filters' => $preference?->filters]);
    }

    /**
     * Saves the current user's task-report filter selections for this project, overwriting
     * whatever was saved before. The frontend calls this every time a real search runs, not
     * on every individual filter change.
     */
    public function updateTaskFilterPreferences(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $filters = $request->validate($this->filterRules());

        ReportFilterPreference::updateOrCreate(
            ['user_id' => $request->user()->id, 'project_id' => $project->id],
            ['filters' => $filters],
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Forgets the current user's saved filters for this project (called on Reset) — so the
     * *next* fresh visit, on any browser/device, lands back on the empty prompt state instead
     * of silently re-running whatever was last searched for.
     */
    public function destroyTaskFilterPreferences(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        ReportFilterPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('project_id', $project->id)
            ->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Export the same filtered task list as a branded PDF, matching the on-screen
     * report's column order (status, due date(s), name, assignee, priority), with an
     * optional trailing "Details" column holding each task's full content as plain text.
     */
    public function exportTasksPdf(Request $request, Project $project): \Illuminate\Http\Response
    {
        Gate::authorize('view', $project);

        [$tasks, $includeDetails, $projectNames, $mode] = $this->tasksForExport($request, $project);

        $contents = $this->reports()->pdfContents($project, $tasks, $includeDetails, $projectNames, $mode);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->reports()->filename($project, 'pdf').'"',
        ]);
    }

    /**
     * Export the same filtered task list as a Word table.
     */
    public function exportTasksWord(Request $request, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        [$tasks, $includeDetails, $projectNames, $mode] = $this->tasksForExport($request, $project);
        $hasSubprojects = count($projectNames) > 1;

        $project->loadMissing('kanbanColumns');

        $phpWord = new PhpWord;
        $section = $phpWord->addSection(['orientation' => 'landscape']);

        $section->addText($project->name.' — Task Report', ['bold' => true, 'size' => 18, 'color' => '0F172A']);
        $section->addText('Generated '.now()->format('m/d/Y'), ['size' => 9, 'color' => '6366F1']);
        $section->addTextBreak();

        $headerStyle = ['bgColor' => 'F1F5F9'];
        $headerFontStyle = ['bold' => true, 'size' => 9];
        $cellFontStyle = ['size' => 9];

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'CBD5E1', 'width' => 100 * 50, 'unit' => 'pct']);

        $table->addRow();
        if ($hasSubprojects) {
            $table->addCell(1800, $headerStyle)->addText('Project', $headerFontStyle);
        }
        $table->addCell(2000, $headerStyle)->addText('Status', $headerFontStyle);
        $table->addCell(1600, $headerStyle)->addText($mode === 'done' ? 'Done Date' : 'Due Date', $headerFontStyle);
        $table->addCell(3500, $headerStyle)->addText('Task Name', $headerFontStyle);
        $table->addCell(2000, $headerStyle)->addText('Assignee', $headerFontStyle);
        $table->addCell(1400, $headerStyle)->addText('Priority', $headerFontStyle);
        $table->addCell(2200, $headerStyle)->addText('Tags', $headerFontStyle);
        if ($includeDetails) {
            $table->addCell(4000, $headerStyle)->addText('Details', $headerFontStyle);
        }

        foreach ($tasks as $task) {
            $table->addRow();
            if ($hasSubprojects) {
                $table->addCell(1800)->addText($projectNames[$task->project_id] ?? '—', $cellFontStyle);
            }
            $table->addCell(2000)->addText($this->statusLabel($task, $project->kanbanColumns), $cellFontStyle);
            $table->addCell(1600)->addText($this->formatDate($this->dueOrDoneDateValue($task, $mode)), $cellFontStyle);
            $table->addCell(3500)->addText($task->name ?? '', $cellFontStyle);
            $table->addCell(2000)->addText($this->assigneeLabel($task), $cellFontStyle);
            $table->addCell(1400)->addText($task->priority ? ucfirst($task->priority) : '—', $cellFontStyle);
            $table->addCell(2200)->addText($this->tagsLabel($task), $cellFontStyle);
            if ($includeDetails) {
                $table->addCell(4000)->addText($this->plainTextContent($task->content), $cellFontStyle);
            }
        }

        $filename = Str::slug($project->name).'-task-report';

        return response()->streamDownload(function () use ($phpWord) {
            $phpWord->save('php://output', 'Word2007');
        }, $filename.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Export the same filtered task list as an Excel workbook.
     */
    public function exportTasksExcel(Request $request, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        [$tasks, $includeDetails, $projectNames, $mode] = $this->tasksForExport($request, $project);

        $contents = $this->reports()->excelContents($project, $tasks, $includeDetails, $projectNames, $mode);

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $this->reports()->filename($project, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Export the same filtered task list as a native Google Sheet, landing directly in the
     * exporting user's own Drive. Returns 428 with a connect_url if the user hasn't
     * connected a Google account yet, so the frontend can send them through the OAuth flow
     * instead of just showing an error.
     */
    public function exportTasksGoogleSheet(Request $request, Project $project, GoogleExportService $service): JsonResponse
    {
        Gate::authorize('view', $project);

        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return response()->json([
                'message' => 'Connect your Google account to export to Google Sheets.',
                'connect_url' => route('integrations.google.connect'),
            ], 428);
        }

        [$headers, $rows] = $this->googleExportHeadersAndRows($request, $project);

        $sheet = $service->createSheet($accessToken, Str::slug($project->name).'-task-report', $headers, $rows);

        return response()->json($sheet);
    }

    /**
     * Export the same filtered task list as a native Google Doc, landing directly in the
     * exporting user's own Drive. Same 428/connect_url handling as the Sheets export above.
     */
    public function exportTasksGoogleDoc(Request $request, Project $project, GoogleExportService $service): JsonResponse
    {
        Gate::authorize('view', $project);

        $accessToken = $service->getValidAccessToken($request->user());

        if (! $accessToken) {
            return response()->json([
                'message' => 'Connect your Google account to export to Google Docs.',
                'connect_url' => route('integrations.google.connect'),
            ], 428);
        }

        [$headers, $rows] = $this->googleExportHeadersAndRows($request, $project);

        $doc = $service->createDoc($accessToken, Str::slug($project->name).'-task-report', $headers, $rows);

        return response()->json($doc);
    }

    /**
     * Shared row-building for both Google export formats — same column set/formatting as the
     * Excel export, just handed to the Sheets/Docs API instead of PhpSpreadsheet.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function googleExportHeadersAndRows(Request $request, Project $project): array
    {
        [$tasks, $includeDetails, $projectNames, $mode] = $this->tasksForExport($request, $project);

        return $this->reports()->headersAndRows($project, $tasks, $includeDetails, $projectNames, $mode);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(): array
    {
        return [
            'assignee' => ['nullable', 'array'],
            'assignee.*' => ['string'],
            'task_status' => ['nullable', 'array'],
            'task_status.*' => ['string'],
            'priority' => ['nullable', 'array'],
            'priority.*' => ['string', 'in:low,medium,high'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'mode' => ['nullable', 'string', 'in:due,done'],
            'project_id' => ['nullable', 'array'],
            'project_id.*' => ['string'],
            'category_id' => ['nullable', 'array'],
            'category_id.*' => ['string'],
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Document>, 1: bool, 2: array<string, string>, 3: string}
     */
    private function tasksForExport(Request $request, Project $project): array
    {
        $validated = $request->validate($this->filterRules() + [
            'include_details' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:status,due_at,status_changed_at,external_due_at,name,assignee,priority,project_name,tags'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        return $this->reports()->tasksForExport($validated, $project);
    }

    private function reports(): TaskReportBuilder
    {
        return app(TaskReportBuilder::class);
    }

    /**
     * @param  array<string, string>  $projectNames
     * @return array<string, mixed>
     */
    private function taskToArray(Document $task, array $projectNames = []): array
    {
        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'project_name' => $projectNames[$task->project_id] ?? null,
            'name' => $task->name,
            'due_at' => $task->due_at,
            'external_due_at' => $task->external_due_at,
            'status_changed_at' => $task->status_changed_at,
            'priority' => $task->priority,
            'task_status' => $task->task_status,
            'assignee_id' => $task->assignee_id,
            'pending_assignee_invitation_id' => $task->pending_assignee_invitation_id,
            // Everything below is only needed to open this task in the slide-in detail
            // sheet (see TaskReport.vue) without a full page navigation — not shown
            // anywhere in the report table itself.
            'content' => $task->content,
            'type' => $task->type,
            'custom_prompt' => $task->custom_prompt,
            'locked_project_type_id' => $task->locked_project_type_id,
            'locked_next_workflow_step_exists' => $task->locked_next_workflow_step_exists,
            'last_ai_template_id' => $task->last_ai_template_id,
            'processed_at' => $task->processed_at,
            'updated_at' => $task->updated_at,
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ] : null,
            'pending_assignee' => $task->pendingAssignee ? [
                'id' => $task->pendingAssignee->id,
                'email' => $task->pendingAssignee->email,
                'first_name' => $task->pendingAssignee->first_name,
                'last_name' => $task->pendingAssignee->last_name,
            ] : null,
            'categories' => $task->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
            ])->all(),
            'comments' => $task->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'user_id' => $comment->user_id,
                'body' => $comment->body,
                'commentable_type' => $comment->commentable_type,
                'commentable_id' => $comment->commentable_id,
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
                'user' => $comment->user ? [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'first_name' => $comment->user->first_name,
                    'last_name' => $comment->user->last_name,
                ] : null,
            ])->all(),
        ];
    }
}
