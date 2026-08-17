<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsTaskFields;
use App\Models\Document;
use App\Models\Project;
use App\Services\Google\GoogleExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
        $query = $this->buildTasksQuery($filters, $project);

        $tasks = $query?->get([
            'id', 'project_id', 'name', 'due_at', 'external_due_at', 'priority',
            'task_status', 'assignee_id', 'pending_assignee_invitation_id',
        ]) ?? collect();

        $projectNames = $this->projectNamesMap($project);

        return response()->json($tasks->map(fn (Document $task) => $this->taskToArray($task, $projectNames)));
    }

    /**
     * Export the same filtered task list as a branded PDF, matching the on-screen
     * report's column order (status, due date(s), name, assignee, priority), with an
     * optional trailing "Details" column holding each task's full content as plain text.
     */
    public function exportTasksPdf(Request $request, Project $project): \Illuminate\Http\Response
    {
        Gate::authorize('view', $project);

        [$tasks, $includeDetails, $projectNames] = $this->tasksForExport($request, $project);

        $project->loadMissing('client.organization', 'kanbanColumns');
        $organization = $project->client?->organization;

        $pdf = Pdf::loadView('pdfs.task-report', [
            'project' => $project,
            'client' => $project->client,
            'tasks' => $tasks,
            'columns' => $project->kanbanColumns,
            'includeDetails' => $includeDetails,
            'usesExternalDueDates' => (bool) $organization?->uses_external_due_dates,
            'hasSubprojects' => count($projectNames) > 1,
            'projectNames' => $projectNames,
            'logoPath' => $project->getFirstMedia('logo')?->getPath('preview'),
            'headerImagePath' => $organization?->getFirstMedia('pdf_header')?->getPath('preview'),
            'footerImagePath' => $organization?->getFirstMedia('pdf_footer')?->getPath('preview'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download(Str::slug($project->name).'-task-report.pdf');
    }

    /**
     * Export the same filtered task list as a Word table.
     */
    public function exportTasksWord(Request $request, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        [$tasks, $includeDetails, $projectNames] = $this->tasksForExport($request, $project);
        $hasSubprojects = count($projectNames) > 1;

        $project->loadMissing('kanbanColumns');

        $phpWord = new PhpWord;
        $section = $phpWord->addSection(['orientation' => 'landscape']);

        $section->addText($project->name.' — Task Report', ['bold' => true, 'size' => 18, 'color' => '0F172A']);
        $section->addText('Generated '.now()->format('F j, Y'), ['size' => 9, 'color' => '6366F1']);
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
        $table->addCell(1600, $headerStyle)->addText('Due Date', $headerFontStyle);
        $table->addCell(3500, $headerStyle)->addText('Task Name', $headerFontStyle);
        $table->addCell(2000, $headerStyle)->addText('Assignee', $headerFontStyle);
        $table->addCell(1400, $headerStyle)->addText('Priority', $headerFontStyle);
        if ($includeDetails) {
            $table->addCell(4000, $headerStyle)->addText('Details', $headerFontStyle);
        }

        foreach ($tasks as $task) {
            $table->addRow();
            if ($hasSubprojects) {
                $table->addCell(1800)->addText($projectNames[$task->project_id] ?? '—', $cellFontStyle);
            }
            $table->addCell(2000)->addText($this->statusLabel($task, $project->kanbanColumns), $cellFontStyle);
            $table->addCell(1600)->addText($this->formatDate($task->due_at), $cellFontStyle);
            $table->addCell(3500)->addText($task->name ?? '', $cellFontStyle);
            $table->addCell(2000)->addText($this->assigneeLabel($task), $cellFontStyle);
            $table->addCell(1400)->addText($task->priority ? ucfirst($task->priority) : '—', $cellFontStyle);
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

        [$tasks, $includeDetails, $projectNames] = $this->tasksForExport($request, $project);
        $hasSubprojects = count($projectNames) > 1;

        $project->loadMissing('kanbanColumns');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Task Report');

        $headers = $hasSubprojects
            ? ['Project', 'Status', 'Due Date', 'Task Name', 'Assignee', 'Priority']
            : ['Status', 'Due Date', 'Task Name', 'Assignee', 'Priority'];
        if ($includeDetails) {
            $headers[] = 'Details';
        }

        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $header);
        }
        $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);
        $sheet->getStyle([1, 1, count($headers), 1])->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');

        $row = 2;
        foreach ($tasks as $task) {
            $col = 'A';
            if ($hasSubprojects) {
                $sheet->setCellValue($col.$row, $projectNames[$task->project_id] ?? '—');
                $col++;
            }
            $sheet->setCellValue($col.$row, $this->statusLabel($task, $project->kanbanColumns));
            $col++;
            $sheet->setCellValue($col.$row, $this->formatDate($task->due_at));
            $col++;
            $sheet->setCellValue($col.$row, $task->name ?? '');
            $col++;
            $sheet->setCellValue($col.$row, $this->assigneeLabel($task));
            $col++;
            $sheet->setCellValue($col.$row, $task->priority ? ucfirst($task->priority) : '—');
            $col++;
            if ($includeDetails) {
                $sheet->setCellValue($col.$row, $this->plainTextContent($task->content));
            }
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = Str::slug($project->name).'-task-report.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
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
        [$tasks, $includeDetails, $projectNames] = $this->tasksForExport($request, $project);
        $hasSubprojects = count($projectNames) > 1;

        $project->loadMissing('kanbanColumns');

        $headers = $hasSubprojects
            ? ['Project', 'Status', 'Due Date', 'Task Name', 'Assignee', 'Priority']
            : ['Status', 'Due Date', 'Task Name', 'Assignee', 'Priority'];
        if ($includeDetails) {
            $headers[] = 'Details';
        }

        $rows = $tasks->map(function (Document $task) use ($project, $includeDetails, $hasSubprojects, $projectNames) {
            $row = [];
            if ($hasSubprojects) {
                $row[] = $projectNames[$task->project_id] ?? '—';
            }
            $row[] = $this->statusLabel($task, $project->kanbanColumns);
            $row[] = $this->formatDate($task->due_at);
            $row[] = $task->name ?? '';
            $row[] = $this->assigneeLabel($task);
            $row[] = $task->priority ? ucfirst($task->priority) : '—';
            if ($includeDetails) {
                $row[] = $this->plainTextContent($task->content);
            }

            return $row;
        })->all();

        return [$headers, $rows];
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
            'project_id' => ['nullable', 'array'],
            'project_id.*' => ['string'],
        ];
    }

    /**
     * This project's own id plus its direct sub-projects' ids (2-level cap, mirroring
     * Project::calendarItems()) — the report always spans a project and its sub-projects,
     * not just the one being viewed.
     *
     * @return array<int, string>
     */
    private function projectIdsIncludingChildren(Project $project): array
    {
        $project->loadMissing('children:id,parent_id,name');

        return [$project->id, ...$project->children->map(fn (Project $child) => (string) $child->id)->values()];
    }

    /**
     * Maps every project/sub-project id in scope to its name, so exports can label each
     * task's originating project without an extra per-row relation load.
     *
     * @return array<string, string>
     */
    private function projectNamesMap(Project $project): array
    {
        $project->loadMissing('children:id,parent_id,name');

        $names = [(string) $project->id => (string) $project->name];
        foreach ($project->children as $child) {
            $names[(string) $child->id] = (string) $child->name;
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    private function stringValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Document>|null
     */
    private function buildTasksQuery(array $filters, Project $project): ?Builder
    {
        $taskTypeKeys = $project->documentTypeCatalog()
            ->filter(fn ($definition) => $definition->is_task)
            ->keys()
            ->all();

        if (empty($taskTypeKeys)) {
            return null;
        }

        $assignees = $this->stringValues($filters['assignee'] ?? null);
        $taskStatuses = $this->stringValues($filters['task_status'] ?? null);
        $priorities = $this->stringValues($filters['priority'] ?? null);
        $dueFrom = is_string($filters['due_from'] ?? null) ? $filters['due_from'] : null;
        $dueTo = is_string($filters['due_to'] ?? null) ? $filters['due_to'] : null;
        $projectIds = $this->stringValues($filters['project_id'] ?? null);

        $query = Document::query()
            ->whereIn('project_id', $this->projectIdsIncludingChildren($project))
            ->whereIn('type', $taskTypeKeys);

        if ($assignees !== []) {
            // Three disjoint kinds of "assignee" value can appear in the same multi-select:
            // a real user id, a pending invitee (`inv:{id}`), and the "Unassigned" sentinel,
            // which matches neither assignee_id nor pending_assignee_invitation_id being set.
            $invitationIds = [];
            $userIds = [];
            $wantsUnassigned = false;

            foreach ($assignees as $value) {
                if ($value === 'unassigned') {
                    $wantsUnassigned = true;
                } elseif (str_starts_with($value, 'inv:')) {
                    $invitationIds[] = (int) substr($value, 4);
                } else {
                    $userIds[] = (int) $value;
                }
            }

            $query->where(function (Builder $subQuery) use ($invitationIds, $userIds, $wantsUnassigned) {
                if ($invitationIds !== []) {
                    $subQuery->orWhereIn('pending_assignee_invitation_id', $invitationIds);
                }
                if ($userIds !== []) {
                    $subQuery->orWhereIn('assignee_id', $userIds);
                }
                if ($wantsUnassigned) {
                    $subQuery->orWhere(function (Builder $unassignedQuery) {
                        $unassignedQuery->whereNull('assignee_id')->whereNull('pending_assignee_invitation_id');
                    });
                }
            });
        }

        if ($taskStatuses !== []) {
            $query->whereIn('task_status', $taskStatuses);
        }

        if ($priorities !== []) {
            $query->whereIn('priority', $priorities);
        }

        if (! empty($dueFrom)) {
            $query->whereDate('due_at', '>=', $dueFrom);
        }

        if (! empty($dueTo)) {
            $query->whereDate('due_at', '<=', $dueTo);
        }

        if ($projectIds !== []) {
            $query->whereIn('project_id', $projectIds);
        }

        return $query
            ->with(['assignee:id,first_name,last_name', 'pendingAssignee:id,email,first_name,last_name'])
            ->orderBy('due_at');
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Document>, 1: bool, 2: array<string, string>}
     */
    private function tasksForExport(Request $request, Project $project): array
    {
        $validated = $request->validate($this->filterRules() + [
            'include_details' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:status,due_at,external_due_at,name,assignee,priority,project_name'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $includeDetails = (bool) ($validated['include_details'] ?? false);

        $query = $this->buildTasksQuery($validated, $project);

        $columns = [
            'id', 'project_id', 'name', 'due_at', 'external_due_at', 'priority',
            'task_status', 'assignee_id', 'pending_assignee_invitation_id',
        ];
        if ($includeDetails) {
            $columns[] = 'content';
        }

        $tasks = $query?->get($columns) ?? collect();
        $projectNames = $this->projectNamesMap($project);

        // The on-screen table sorts client-side, independently of this query's own
        // ->orderBy('due_at') — a downloaded file can't be re-sorted after the fact, so it
        // needs to replicate whichever column/direction the user had active when exporting.
        $project->loadMissing('kanbanColumns');
        $sortBy = is_string($validated['sort_by'] ?? null) ? $validated['sort_by'] : 'due_at';
        $sortDir = is_string($validated['sort_dir'] ?? null) ? $validated['sort_dir'] : 'asc';
        $tasks = $this->sortTasksForExport($tasks, $project, $sortBy, $sortDir, $projectNames);

        return [$tasks, $includeDetails, $projectNames];
    }

    /**
     * Mirrors TaskReportTable.vue's own sortValue()/compare() exactly (same column
     * mapping, same "nulls always sort last regardless of direction" rule) so a Details
     * export always matches what was on screen when the user clicked the button.
     *
     * @param  \Illuminate\Support\Collection<int, Document>  $tasks
     * @param  array<string, string>  $projectNames
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private function sortTasksForExport(\Illuminate\Support\Collection $tasks, Project $project, string $sortBy, string $sortDir, array $projectNames = []): \Illuminate\Support\Collection
    {
        $direction = $sortDir === 'desc' ? -1 : 1;
        $priorityWeight = ['low' => 1, 'medium' => 2, 'high' => 3];

        $sortValue = function (Document $task) use ($sortBy, $project, $priorityWeight, $projectNames) {
            return match ($sortBy) {
                'status' => $project->kanbanColumns->firstWhere('key', $task->task_status)?->order,
                'external_due_at' => $task->external_due_at,
                'name' => mb_strtolower($task->name ?? ''),
                'assignee' => mb_strtolower($this->assigneeLabel($task)),
                'priority' => $task->priority ? ($priorityWeight[$task->priority] ?? null) : null,
                'project_name' => mb_strtolower($projectNames[$task->project_id] ?? ''),
                default => $task->due_at,
            };
        };

        $items = $tasks->all();

        usort($items, function (Document $a, Document $b) use ($sortValue, $direction) {
            $valueA = $sortValue($a);
            $valueB = $sortValue($b);

            if ($valueA === null && $valueB === null) {
                return 0;
            }
            if ($valueA === null) {
                return 1;
            }
            if ($valueB === null) {
                return -1;
            }

            return $direction * ($valueA <=> $valueB);
        });

        return collect($items);
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
            'priority' => $task->priority,
            'task_status' => $task->task_status,
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
        ];
    }
}
