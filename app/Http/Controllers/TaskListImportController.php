<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskListImportRequest;
use App\Models\Document;
use App\Models\KanbanColumn;
use App\Models\Project;
use App\Services\TaskListImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class TaskListImportController extends Controller
{
    public function __construct(private readonly TaskListImportService $importService) {}

    /**
     * Parses an uploaded spreadsheet and returns its headers, rows, and a best-guess column
     * mapping for the confirmation modal — nothing is persisted here. The modal round-trips
     * this same headers/rows payload back to store() once the user confirms (or edits) the
     * mapping, so there's no server-side state to hold between the two requests.
     */
    public function analyze(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $file = $request->file('file');

        return response()->json([
            ...$this->importService->analyze($file),
            'original_filename' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Creates one `task_list_import` Document — a permanent record of the import, its content
     * already normalized into the confirmed field shape rather than the original file, so
     * there's nothing left to reinterpret later — then one `task` Document per row, processed
     * one at a time. A bad row (missing name, or any other failure) is skipped and recorded
     * rather than aborting the rest of the import.
     */
    public function store(StoreTaskListImportRequest $request, Project $project): RedirectResponse
    {
        /** @var array{original_filename: string|null, headers: list<string>, rows: list<list<string>>, mapping: array<string, string|null>} $validated */
        $validated = $request->validated();
        $headers = $validated['headers'];
        $mapping = $validated['mapping'];

        $project->loadMissing('client.organization.users', 'client.organization.invitations', 'kanbanColumns');
        $organization = $project->client?->organization;
        abort_if($organization === null, 404);
        $kanbanColumns = $project->kanbanColumns;
        $defaultColumn = $kanbanColumns->first();
        $defaultStatus = $defaultColumn !== null ? $defaultColumn->key : 'todo';

        $importDocument = $project->documents()->create([
            'type' => 'task_list_import',
            'name' => $validated['original_filename'] ?? 'Imported task list',
            'content' => '',
            'metadata' => ['original_filename' => $validated['original_filename'] ?? null],
        ]);

        $normalizedRows = [];
        $skipped = [];
        $createdCount = 0;

        foreach ($validated['rows'] as $index => $row) {
            $cell = fn (string $field): string => $this->cellFor($row, $headers, $mapping[$field] ?? null);

            $name = trim($cell('name'));
            if ($name === '') {
                $skipped[] = ['row' => $index + 2, 'reason' => 'Missing task name.'];

                continue;
            }

            $priority = $this->normalizePriority($cell('priority'));
            $taskStatus = $this->normalizeStatus($cell('task_status'), $kanbanColumns, $defaultStatus);
            $dueAt = $this->parseDate($cell('due_at'));
            $assigneeText = $cell('assignee');
            $assignee = $this->importService->resolveAssignee($assigneeText ?: null, $organization->users, $organization->invitations);

            $normalizedRows[] = [
                'name' => $name,
                'priority' => $priority,
                'task_status' => $taskStatus,
                'due_at' => $dueAt,
                'assignee' => $assigneeText ?: null,
            ];

            try {
                $task = $project->documents()->make();
                // DocumentObserver::creating() defaults task_status to 'todo' whenever the
                // legacy `status` column is null — forceFill both together (status isn't
                // fillable, it predates task_status and nothing else still writes to it) so
                // the mapped status from the sheet survives instead of being silently
                // overwritten the moment this saves.
                $task->forceFill([
                    'type' => 'task',
                    'name' => $name,
                    'content' => '',
                    'priority' => $priority,
                    'status' => $taskStatus,
                    'task_status' => $taskStatus,
                    'due_at' => $dueAt,
                    'assignee_id' => $assignee['assignee_id'],
                    'pending_assignee_invitation_id' => $assignee['pending_assignee_invitation_id'],
                    'metadata' => ['imported_from' => $importDocument->id],
                ]);
                $task->save();

                $createdCount++;
            } catch (\Throwable $e) {
                $skipped[] = ['row' => $index + 2, 'reason' => $e->getMessage()];
            }
        }

        $importDocument->update([
            'content' => json_encode($normalizedRows, JSON_PRETTY_PRINT),
            'metadata' => [
                'original_filename' => $validated['original_filename'] ?? null,
                'created_count' => $createdCount,
                'skipped' => $skipped,
                'status' => $skipped === [] ? 'completed' : 'completed_with_errors',
            ],
        ]);

        $message = $skipped === []
            ? "Imported {$createdCount} tasks."
            : "Imported {$createdCount} tasks, skipped ".count($skipped).' row(s) — see the import record for details.';

        return redirect()->to(route('projects.show', $project).'?tab=tasks')->with('success', $message);
    }

    /**
     * Looks up a mapped column's value for one row — $mapping[$field] holds the header text the
     * user chose (or null if that field wasn't mapped to anything), and headers/rows are kept as
     * parallel arrays rather than associative ones since spreadsheet headers aren't guaranteed
     * unique.
     *
     * @param  list<string>  $row
     * @param  list<string>  $headers
     */
    private function cellFor(array $row, array $headers, ?string $header): string
    {
        if ($header === null) {
            return '';
        }

        $index = array_search($header, $headers, true);

        return $index === false ? '' : trim((string) ($row[$index] ?? ''));
    }

    private function normalizePriority(string $raw): string
    {
        $normalized = mb_strtolower(trim($raw));

        return in_array($normalized, ['low', 'medium', 'high'], true) ? $normalized : 'medium';
    }

    /**
     * @param  Collection<int, KanbanColumn>  $columns
     */
    private function normalizeStatus(string $raw, Collection $columns, string $default): string
    {
        $normalized = mb_strtolower(trim($raw));

        if ($normalized === '') {
            return $default;
        }

        $match = $columns->first(
            fn (KanbanColumn $column) => mb_strtolower($column->key) === $normalized || mb_strtolower($column->label) === $normalized
        );

        return $match !== null ? $match->key : $default;
    }

    private function parseDate(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
