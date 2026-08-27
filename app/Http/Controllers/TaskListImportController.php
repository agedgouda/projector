<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskListImportRequest;
use App\Jobs\ImportTaskList;
use App\Models\Document;
use App\Models\Project;
use App\Services\TaskListImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Creates the `task_list_import`/`event_list_import` Document immediately — a permanent
     * record of the import, returned right away so the frontend can close the confirmation
     * modal and start listening for this project's TaskListImportProgress broadcasts — then
     * hands the actual row-by-row work to a queued ImportTaskList job. Row creation isn't done
     * inline here specifically so a large import (up to the validated 5000-row cap) doesn't tie
     * up the request/response cycle, and so the frontend can show live "X of Y" progress instead
     * of just a spinner for however long that takes.
     */
    public function store(StoreTaskListImportRequest $request, Project $project): JsonResponse
    {
        /** @var array{list_type: string, original_filename: string|null, headers: list<string>, rows: list<list<string>>, mapping: array<string, string|null>} $validated */
        $validated = $request->validated();

        $isEvent = $validated['list_type'] === 'event';

        $importDocument = $project->documents()->create([
            'type' => $isEvent ? 'event_list_import' : 'task_list_import',
            'name' => $validated['original_filename'] ?? ($isEvent ? 'Imported event list' : 'Imported task list'),
            'content' => '[]',
            'metadata' => [
                'original_filename' => $validated['original_filename'] ?? null,
                'created_count' => 0,
                'skipped' => [],
                'status' => 'importing',
            ],
        ]);

        ImportTaskList::dispatch(
            $importDocument,
            $validated['list_type'],
            $validated['headers'],
            $validated['rows'],
            $validated['mapping'],
        );

        return response()->json([
            'import_document_id' => $importDocument->id,
            'total' => count($validated['rows']),
        ]);
    }
}
