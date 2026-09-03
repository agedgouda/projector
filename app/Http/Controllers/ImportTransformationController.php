<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyImportTransformationRequest;
use App\Http\Requests\ApplyTextImportTransformationRequest;
use App\Jobs\ExtractTextRecords;
use App\Jobs\ImportTaskList;
use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
use App\Services\Ai\SpreadsheetClassificationService;
use App\Services\Ai\TextExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Handles both a spreadsheet source (headers/rows + a per-pass column mapping) and a text/
 * document source (raw text + a per-pass AI extraction rule) turning into any mix of Task and
 * Event documents — see SpreadsheetClassificationService vs TextExtractionService for how each
 * source proposes its passes, and ImportTaskList vs ExtractTextRecords for how each pass
 * actually gets applied. Every pass, regardless of source, still produces fully separate Task/
 * Event documents with no relationship between them in the database.
 */
class ImportTransformationController extends Controller
{
    /**
     * AI-classifies an already-analyzed spreadsheet (see TaskListImportController::analyze(),
     * reused unchanged for parsing) into one proposed "pass" per record type it actually finds
     * — nothing is persisted here, same as analyze(). The frontend round-trips the result back
     * to applySpreadsheet() once the user has reviewed/edited each pass's mapping.
     */
    public function classifySpreadsheet(Request $request, Project $project, SpreadsheetClassificationService $classificationService): JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $validated = $request->validate([
            'headers' => ['required', 'array'],
            'headers.*' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
        ]);

        $project->loadMissing('client.organization');

        $result = $classificationService->classify(
            $validated['headers'],
            $validated['rows'],
            $project->client?->organization_id,
        );

        return response()->json($result);
    }

    /**
     * Runs one confirmed pass per detected/saved record type over the same uploaded sheet —
     * one ImportTaskList dispatch per pass, completely unchanged from a single-type import,
     * so each pass still produces its own fully separate Task or Event documents. When
     * `ai_template_id` names a saved transformation, every row created by every pass is stamped
     * with it (see ImportTaskList's aiTemplateId param) so it shows up as that document's
     * originating transformation like any other AI-produced document.
     */
    public function applySpreadsheet(ApplyImportTransformationRequest $request, Project $project): JsonResponse
    {
        /** @var array{original_filename: string|null, headers: list<string>, rows: list<list<string>>, ai_template_id: int|null, passes: list<array{list_type: string, mapping: array<string, string|null>}>} $validated */
        $validated = $request->validated();

        $results = [];

        foreach ($validated['passes'] as $pass) {
            $isEvent = $pass['list_type'] === 'event';

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
                $pass['list_type'],
                $validated['headers'],
                $validated['rows'],
                $pass['mapping'],
                $validated['ai_template_id'] ?? null,
            );

            $results[] = [
                'list_type' => $pass['list_type'],
                'import_document_id' => $importDocument->id,
            ];
        }

        return response()->json([
            'passes' => $results,
            'total' => count($validated['rows']),
        ]);
    }

    /**
     * AI-classifies a raw text/document source into one proposed "pass" per record type it
     * actually finds — the text-source counterpart to classifySpreadsheet(). Nothing is
     * persisted here; the frontend round-trips the result back to applyText() once the user has
     * reviewed/edited each pass's extraction_rule.
     */
    public function classifyText(Request $request, Project $project, TextExtractionService $extractionService): JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:100000'],
        ]);

        $project->loadMissing('client.organization');

        $result = $extractionService->classify($validated['text'], $project->client?->organization_id);

        return response()->json($result);
    }

    /**
     * Runs one confirmed pass per detected/saved record type over the same source text — one
     * ExtractTextRecords dispatch per pass. Provenance (ai_template_id) works exactly like
     * applySpreadsheet(): every record a pass creates is stamped with the saved transformation
     * that drove it, when there is one.
     */
    public function applyText(ApplyTextImportTransformationRequest $request, Project $project): JsonResponse
    {
        /** @var array{original_filename: string|null, text: string, ai_template_id: int|null, passes: list<array{list_type: string, extraction_rule: string}>} $validated */
        $validated = $request->validated();

        $results = [];

        foreach ($validated['passes'] as $pass) {
            $isEvent = $pass['list_type'] === 'event';

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

            ExtractTextRecords::dispatch(
                $importDocument,
                $pass['list_type'],
                $validated['text'],
                $pass['extraction_rule'],
                $validated['ai_template_id'] ?? null,
            );

            $results[] = [
                'list_type' => $pass['list_type'],
                'import_document_id' => $importDocument->id,
            ];
        }

        return response()->json(['passes' => $results]);
    }

    /**
     * Lists saved import transformations (spreadsheet or text) visible to the current
     * organization (its own, plus any global ones) — the "use a saved transformation" picker's
     * data source.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AiTemplate::class);

        $orgId = getPermissionsTeamId();

        $templates = AiTemplate::whereIn('type', ['spreadsheet_import', 'text_import'])
            ->where(function ($query) use ($orgId) {
                $query->whereNull('organization_id')->orWhere('organization_id', $orgId);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'type', 'import_config']);

        return response()->json(['transformations' => $templates]);
    }
}
