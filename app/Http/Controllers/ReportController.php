<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
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

        return response()->json($tasks->map(fn (Document $task) => $this->taskToArray($task)));
    }

    /**
     * Export the same filtered task list as a branded PDF, matching the on-screen
     * report's column order (status, due date(s), name, assignee, priority), with an
     * optional trailing "Details" column holding each task's full content as plain text.
     */
    public function exportTasksPdf(Request $request, Project $project): \Illuminate\Http\Response
    {
        Gate::authorize('view', $project);

        [$tasks, $includeDetails] = $this->tasksForExport($request, $project);

        $project->loadMissing('client.organization', 'kanbanColumns');
        $organization = $project->client?->organization;

        $pdf = Pdf::loadView('pdfs.task-report', [
            'project' => $project,
            'client' => $project->client,
            'tasks' => $tasks,
            'columns' => $project->kanbanColumns,
            'includeDetails' => $includeDetails,
            'usesExternalDueDates' => (bool) $organization?->uses_external_due_dates,
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

        [$tasks, $includeDetails] = $this->tasksForExport($request, $project);

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

        [$tasks, $includeDetails] = $this->tasksForExport($request, $project);

        $project->loadMissing('kanbanColumns');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Task Report');

        $headers = ['Status', 'Due Date', 'Task Name', 'Assignee', 'Priority'];
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
            $sheet->setCellValue('A'.$row, $this->statusLabel($task, $project->kanbanColumns));
            $sheet->setCellValue('B'.$row, $this->formatDate($task->due_at));
            $sheet->setCellValue('C'.$row, $task->name ?? '');
            $sheet->setCellValue('D'.$row, $this->assigneeLabel($task));
            $sheet->setCellValue('E'.$row, $task->priority ? ucfirst($task->priority) : '—');
            if ($includeDetails) {
                $sheet->setCellValue('F'.$row, $this->plainTextContent($task->content));
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
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(): array
    {
        return [
            'assignee' => ['nullable', 'string'],
            'task_status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
        ];
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

        $assignee = is_string($filters['assignee'] ?? null) ? $filters['assignee'] : null;
        $taskStatus = is_string($filters['task_status'] ?? null) ? $filters['task_status'] : null;
        $priority = is_string($filters['priority'] ?? null) ? $filters['priority'] : null;
        $dueFrom = is_string($filters['due_from'] ?? null) ? $filters['due_from'] : null;
        $dueTo = is_string($filters['due_to'] ?? null) ? $filters['due_to'] : null;

        $query = Document::query()
            ->where('project_id', $project->id)
            ->whereIn('type', $taskTypeKeys);

        if (! empty($assignee)) {
            if (str_starts_with($assignee, 'inv:')) {
                $query->where('pending_assignee_invitation_id', (int) substr($assignee, 4));
            } else {
                $query->where('assignee_id', (int) $assignee);
            }
        }

        if (! empty($taskStatus)) {
            $query->where('task_status', $taskStatus);
        }

        if (! empty($priority)) {
            $query->where('priority', $priority);
        }

        if (! empty($dueFrom)) {
            $query->whereDate('due_at', '>=', $dueFrom);
        }

        if (! empty($dueTo)) {
            $query->whereDate('due_at', '<=', $dueTo);
        }

        return $query
            ->with(['assignee:id,first_name,last_name', 'pendingAssignee:id,email,first_name,last_name'])
            ->orderBy('due_at');
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Document>, 1: bool}
     */
    private function tasksForExport(Request $request, Project $project): array
    {
        $validated = $request->validate($this->filterRules() + [
            'include_details' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:status,due_at,external_due_at,name,assignee,priority'],
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

        // The on-screen table sorts client-side, independently of this query's own
        // ->orderBy('due_at') — a downloaded file can't be re-sorted after the fact, so it
        // needs to replicate whichever column/direction the user had active when exporting.
        $project->loadMissing('kanbanColumns');
        $sortBy = is_string($validated['sort_by'] ?? null) ? $validated['sort_by'] : 'due_at';
        $sortDir = is_string($validated['sort_dir'] ?? null) ? $validated['sort_dir'] : 'asc';
        $tasks = $this->sortTasksForExport($tasks, $project, $sortBy, $sortDir);

        return [$tasks, $includeDetails];
    }

    /**
     * Mirrors TaskReportTable.vue's own sortValue()/compare() exactly (same column
     * mapping, same "nulls always sort last regardless of direction" rule) so a Details
     * export always matches what was on screen when the user clicked the button.
     *
     * @param  \Illuminate\Support\Collection<int, Document>  $tasks
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private function sortTasksForExport(\Illuminate\Support\Collection $tasks, Project $project, string $sortBy, string $sortDir): \Illuminate\Support\Collection
    {
        $direction = $sortDir === 'desc' ? -1 : 1;
        $priorityWeight = ['low' => 1, 'medium' => 2, 'high' => 3];

        $sortValue = function (Document $task) use ($sortBy, $project, $priorityWeight) {
            return match ($sortBy) {
                'status' => $project->kanbanColumns->firstWhere('key', $task->task_status)?->order,
                'external_due_at' => $task->external_due_at,
                'name' => mb_strtolower($task->name ?? ''),
                'assignee' => mb_strtolower($this->assigneeLabel($task)),
                'priority' => $task->priority ? ($priorityWeight[$task->priority] ?? null) : null,
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
     * @return array<string, mixed>
     */
    private function taskToArray(Document $task): array
    {
        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
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

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\KanbanColumn>  $columns
     */
    private function statusLabel(Document $task, \Illuminate\Support\Collection $columns): string
    {
        $column = $columns->firstWhere('key', $task->task_status);

        return $column?->label ?? $task->task_status ?? '—';
    }

    private function assigneeLabel(Document $task): string
    {
        if ($task->assignee) {
            return $task->assignee->name;
        }

        if ($task->pendingAssignee) {
            $name = trim(($task->pendingAssignee->first_name ?? '').' '.($task->pendingAssignee->last_name ?? ''));

            return $name !== '' ? $name : $task->pendingAssignee->email;
        }

        return 'Unassigned';
    }

    private function formatDate(?string $value): string
    {
        if (! $value) {
            return '—';
        }

        return \Illuminate\Support\Carbon::parse($value)->format('M j, Y');
    }

    /**
     * Strips a task's rich-text HTML content down to plain text for the export-only
     * "Details" column — table cells in PDF/Word/Excel can't reasonably render arbitrary
     * HTML, so this normalizes it the same way ProjectAiService does for LLM prompts.
     */
    private function plainTextContent(?string $html): string
    {
        if (! $html) {
            return '';
        }

        $text = (string) preg_replace('/<(p|li|br|h[1-6])[^>]*>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
