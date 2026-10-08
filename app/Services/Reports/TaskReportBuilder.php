<?php

namespace App\Services\Reports;

use App\Http\Controllers\Concerns\FormatsTaskFields;
use App\Models\Document;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the task report (the Reports tab's data) and renders it to a file's bytes. Lives outside
 * ReportController so anything that isn't a web request — the Slack /report command's queued job —
 * produces exactly the same rows, sorting, and Excel/PDF/CSV layout the Reports tab's own
 * downloads do, instead of a second copy that could drift.
 */
class TaskReportBuilder
{
    use FormatsTaskFields;

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
    public function projectNamesMap(Project $project): array
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
    public function buildTasksQuery(array $filters, Project $project): ?Builder
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
        $mode = ($filters['mode'] ?? null) === 'done' ? 'done' : 'due';
        $projectIds = $this->stringValues($filters['project_id'] ?? null);
        $categoryIds = $this->stringValues($filters['category_id'] ?? null);

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

        // "Done" mode retargets the same From/To range onto status_changed_at instead of
        // due_at, and additionally restricts to tasks actually marked done — a task whose
        // status_changed_at happens to fall in range but isn't currently 'done' (e.g. it was
        // done then reopened) shouldn't show up as if it were completed in that window.
        if ($mode === 'done') {
            $query->where('task_status', 'done');

            if (! empty($dueFrom)) {
                $query->whereDate('status_changed_at', '>=', $dueFrom);
            }

            if (! empty($dueTo)) {
                $query->whereDate('status_changed_at', '<=', $dueTo);
            }
        } else {
            if (! empty($dueFrom)) {
                $query->whereDate('due_at', '>=', $dueFrom);
            }

            if (! empty($dueTo)) {
                $query->whereDate('due_at', '<=', $dueTo);
            }
        }

        if ($projectIds !== []) {
            $query->whereIn('project_id', $projectIds);
        }

        if ($categoryIds !== []) {
            // Mirrors the assignee filter's 'unassigned' sentinel above: 'none' matches
            // tasks with zero tags, alongside any real tag ids in the same multi-select —
            // same 'none' sentinel the Kanban board's own tag filter uses (see
            // TAG_FILTER_NONE in useKanbanQueries.ts).
            $wantsNoTags = in_array('none', $categoryIds, true);
            $realCategoryIds = array_values(array_filter($categoryIds, fn (string $id) => $id !== 'none'));

            $query->where(function (Builder $subQuery) use ($realCategoryIds, $wantsNoTags) {
                if ($realCategoryIds !== []) {
                    $subQuery->orWhereHas(
                        'categories',
                        fn (Builder $categoryQuery) => $categoryQuery->whereIn('categories.id', $realCategoryIds)
                    );
                }
                if ($wantsNoTags) {
                    $subQuery->orWhereDoesntHave('categories');
                }
            });
        }

        return $query
            ->with(['assignee:id,first_name,last_name', 'pendingAssignee:id,email,first_name,last_name', 'categories'])
            ->orderBy($mode === 'done' ? 'status_changed_at' : 'due_at');
    }

    /**
     * @param  array<string, mixed>  $validated  Already-validated filter/sort/include_details values.
     * @return array{0: Collection<int, Document>, 1: bool, 2: array<string, string>, 3: string}
     */
    public function tasksForExport(array $validated, Project $project): array
    {
        $includeDetails = (bool) ($validated['include_details'] ?? false);
        $mode = ($validated['mode'] ?? null) === 'done' ? 'done' : 'due';

        $query = $this->buildTasksQuery($validated, $project);

        $columns = [
            'id', 'project_id', 'name', 'due_at', 'external_due_at', 'start_at', 'predecessor_id', 'status_changed_at',
            'priority', 'task_status', 'assignee_id', 'pending_assignee_invitation_id',
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
        $sortBy = is_string($validated['sort_by'] ?? null) ? $validated['sort_by'] : ($mode === 'done' ? 'status_changed_at' : 'due_at');
        $sortDir = is_string($validated['sort_dir'] ?? null) ? $validated['sort_dir'] : 'asc';
        $tasks = $sortBy === 'chain' && $this->usesTaskStartDates($project)
            ? $this->orderAsChains($tasks)
            : $this->sortTasksForExport($tasks, $project, $sortBy, $sortDir, $projectNames);

        return [$tasks, $includeDetails, $projectNames, $mode];
    }

    /**
     * The export in chain order, as the report shows it by default (TaskReportTable.vue / lib/
     * taskChains.ts): each task right after the one it waits on, earliest first. A task whose
     * predecessor isn't in the export starts its own chain. Each task gets a transient
     * `chain_depth` (never saved) that the exports indent its name by.
     *
     * @param  Collection<int, Document>  $tasks
     * @return Collection<int, Document>
     */
    private function orderAsChains(Collection $tasks): Collection
    {
        $startKey = fn (Document $task): string => substr((string) ($task->start_at ?? $task->due_at ?? ''), 0, 10) ?: '9999-99-99';
        $byDate = fn (Document $a, Document $b): int => [$startKey($a), mb_strtolower($a->name ?? '')] <=> [$startKey($b), mb_strtolower($b->name ?? '')];

        $ids = $tasks->mapWithKeys(fn (Document $task) => [$this->taskId($task) => true]);
        $followers = $tasks->filter(fn (Document $task) => $task->predecessor_id !== null)->groupBy('predecessor_id');
        $roots = $tasks->filter(fn (Document $task) => $task->predecessor_id === null || ! $ids->has($task->predecessor_id))->sort($byDate);

        $ordered = [];
        $seen = [];
        $walk = function (Document $task, int $depth) use (&$walk, &$ordered, &$seen, $followers, $byDate): void {
            $id = $this->taskId($task);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $task->setAttribute('chain_depth', $depth);
            $ordered[] = $task;
            foreach ($followers->get($id, collect())->sort($byDate) as $follower) {
                $walk($follower, $depth + 1);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0);
        }

        return collect($ordered);
    }

    /**
     * A task's name as exported: in chain order, indented one step per level (non-breaking
     * spaces, which CSV/Google imports don't trim). Excel replaces this with real cell
     * indentation — see excelContents().
     */
    public function exportTaskName(Document $task, bool $usesTaskStartDates): string
    {
        $name = $task->name ?? '';
        $depth = $this->chainDepth($task);

        return $usesTaskStartDates && $depth > 0 ? str_repeat("\u{00A0}", 4 * $depth).$name : $name;
    }

    public function chainDepth(Document $task): int
    {
        $depth = $task->getAttribute('chain_depth');

        return is_int($depth) ? $depth : 0;
    }

    private function usesTaskStartDates(Project $project): bool
    {
        $project->loadMissing('client.organization');

        return (bool) $project->client?->organization?->uses_task_start_dates;
    }

    private function taskId(Document $task): string
    {
        $key = $task->getKey();

        return is_string($key) ? $key : '';
    }

    /**
     * The column set/formatting shared by the Excel, CSV, and Google exports: Name, Status,
     * Assignee, Start Date (orgs that track start dates), Due Date (Done Date in done mode),
     * Priority, Tags — led by Project when the report spans sub-projects, and followed by
     * Details when asked for.
     *
     * @param  Collection<int, Document>  $tasks
     * @param  array<string, string>  $projectNames
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    public function headersAndRows(Project $project, Collection $tasks, bool $includeDetails, array $projectNames, string $mode): array
    {
        $hasSubprojects = count($projectNames) > 1;

        $project->loadMissing('kanbanColumns');
        $usesTaskStartDates = $this->usesTaskStartDates($project);

        $headers = $hasSubprojects ? ['Project'] : [];
        array_push($headers, 'Name', 'Status', 'Assignee');
        if ($usesTaskStartDates) {
            $headers[] = 'Start Date';
        }
        array_push($headers, $mode === 'done' ? 'Done Date' : 'Due Date', 'Priority', 'Tags');
        if ($includeDetails) {
            $headers[] = 'Details';
        }

        $rows = $tasks->map(function (Document $task) use ($project, $includeDetails, $hasSubprojects, $projectNames, $mode, $usesTaskStartDates) {
            $row = [];
            if ($hasSubprojects) {
                $row[] = $projectNames[$task->project_id] ?? '—';
            }
            $row[] = $this->exportTaskName($task, $usesTaskStartDates);
            $row[] = $this->statusLabel($task, $project->kanbanColumns);
            $row[] = $this->assigneeLabel($task);
            if ($usesTaskStartDates) {
                $row[] = $this->formatDate($task->start_at);
            }
            $row[] = $this->formatDate($this->dueOrDoneDateValue($task, $mode));
            $row[] = $task->priority ? ucfirst($task->priority) : '—';
            $row[] = $this->tagsLabel($task);
            if ($includeDetails) {
                $row[] = $this->plainTextContent($task->content);
            }

            return $row;
        })->all();

        return [$headers, $rows];
    }

    /**
     * @param  Collection<int, Document>  $tasks
     * @param  array<string, string>  $projectNames
     */
    public function excelContents(Project $project, Collection $tasks, bool $includeDetails, array $projectNames, string $mode): string
    {
        [$headers, $rows] = $this->headersAndRows($project, $tasks, $includeDetails, $projectNames, $mode);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Task Report');

        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $header);
        }
        $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);
        $sheet->getStyle([1, 1, count($headers), 1])->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2), $value);
            }
        }

        // Chain nesting as real cell indentation rather than leading spaces.
        $nameColumn = Coordinate::stringFromColumnIndex(count($projectNames) > 1 ? 2 : 1);
        foreach ($tasks->values() as $rowIndex => $task) {
            $depth = $this->chainDepth($task);
            if ($depth > 0) {
                $cell = $nameColumn.($rowIndex + 2);
                $sheet->setCellValue($cell, $task->name ?? '');
                $sheet->getStyle($cell)->getAlignment()->setIndent($depth * 2);
            }
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->write(fn (string $path) => (new Xlsx($spreadsheet))->save($path));
    }

    /**
     * @param  Collection<int, Document>  $tasks
     * @param  array<string, string>  $projectNames
     */
    public function csvContents(Project $project, Collection $tasks, bool $includeDetails, array $projectNames, string $mode): string
    {
        [$headers, $rows] = $this->headersAndRows($project, $tasks, $includeDetails, $projectNames, $mode);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV.');
        }

        fputcsv($handle, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * @param  Collection<int, Document>  $tasks
     * @param  array<string, string>  $projectNames
     */
    public function pdfContents(Project $project, Collection $tasks, bool $includeDetails, array $projectNames, string $mode): string
    {
        $project->loadMissing('client.organization', 'kanbanColumns');
        $organization = $project->client?->organization;

        return Pdf::loadView('pdfs.task-report', [
            'project' => $project,
            'client' => $project->client,
            'tasks' => $tasks,
            'columns' => $project->kanbanColumns,
            'includeDetails' => $includeDetails,
            'isDoneMode' => $mode === 'done',
            'usesTaskStartDates' => (bool) $organization?->uses_task_start_dates,
            'hasSubprojects' => count($projectNames) > 1,
            'projectNames' => $projectNames,
            'logoPath' => $project->getFirstMedia('logo')?->getPath(),
            'headerImagePath' => $organization?->getFirstMedia('pdf_header')?->getPath(),
            'footerImagePath' => $organization?->getFirstMedia('pdf_footer')?->getPath(),
        ])->setPaper('a4', 'landscape')->output();
    }

    public function filename(Project $project, string $extension): string
    {
        return Str::slug($project->name).'-task-report.'.$extension;
    }

    /**
     * PhpSpreadsheet's writer only saves to a path/stream, so this round-trips through a temp
     * file to hand back the bytes.
     *
     * @param  callable(string): void  $save
     */
    private function write(callable $save): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report');
        $save($path);
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
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
                'start_at' => $task->start_at,
                'status_changed_at' => $task->status_changed_at,
                'name' => mb_strtolower($task->name ?? ''),
                'assignee' => mb_strtolower($this->assigneeLabel($task)),
                'priority' => $task->priority ? ($priorityWeight[$task->priority] ?? null) : null,
                'project_name' => mb_strtolower($projectNames[$task->project_id] ?? ''),
                'tags' => $task->categories->isNotEmpty()
                    ? mb_strtolower($task->categories->pluck('name')->sort()->implode(', '))
                    : null,
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
}
