<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyImportTransformationRequest;
use App\Http\Requests\ApplyTextImportTransformationRequest;
use App\Jobs\ExtractTextRecords;
use App\Jobs\ImportTaskList;
use App\Models\AiTemplate;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\User;
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
     *
     * A human has just looked at (and confirmed or edited) every pass's mapping by the time
     * this runs, regardless of whether they got here via a plain manual upload or by resolving
     * a Slack-queued file — recording each one via ProjectImportMapping is what lets
     * ImportSlackFile recognize this exact mapping as already-validated for this project next
     * time, instead of queuing it for review again.
     */
    public function applySpreadsheet(ApplyImportTransformationRequest $request, Project $project): JsonResponse
    {
        /** @var array{original_filename: string|null, headers: list<string>, rows: list<list<string>>, ai_template_id: int|null, passes: list<array{list_type: string, mapping: array<string, string|null>}>} $validated */
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

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

            ProjectImportMapping::record($project, $pass['list_type'], $pass['mapping'], $user, $validated['headers']);

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

        $documentTypes = array_values(
            $project->documentTypeCatalog()
                ->reject(fn ($definition) => in_array($definition->key, ['task', 'event'], true))
                ->map(fn ($definition) => ['key' => $definition->key, 'label' => $definition->label])
                ->all()
        );

        $result = $extractionService->classify($validated['text'], $project->client?->organization_id, $documentTypes);

        return response()->json($result);
    }

    /**
     * Runs one confirmed pass per detected/saved record type over the same source text. A
     * task/event pass works exactly as before — one ExtractTextRecords dispatch, provenance
     * (ai_template_id) stamped the same way applySpreadsheet() does. Any other pass is a human
     * overriding the AI's task/event guess with "this is actually a project document" (Meeting
     * Notes, Transcription, whatever the project's own catalog offers) — there's nothing to
     * extract there, so the whole source text becomes that document's content directly, with no
     * queued job and no extraction_rule involved.
     */
    public function applyText(ApplyTextImportTransformationRequest $request, Project $project): JsonResponse
    {
        /** @var array{original_filename: string|null, text: string, ai_template_id: int|null, passes: list<array{list_type: string, extraction_rule: string|null}>} $validated */
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $results = [];

        foreach ($validated['passes'] as $pass) {
            if (! in_array($pass['list_type'], ['task', 'event'], true)) {
                $document = $project->documents()->create([
                    'type' => $pass['list_type'],
                    'name' => $validated['original_filename'] ?? 'Imported document',
                    'content' => $validated['text'],
                    'creator_id' => $user->id,
                ]);

                $results[] = [
                    'list_type' => $pass['list_type'],
                    'document_id' => $document->id,
                ];

                continue;
            }

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
                // required_if:passes.*.list_type,task,event guarantees this is a non-empty
                // string on this branch — the '' fallback is just satisfying the type checker,
                // never actually reached.
                $pass['extraction_rule'] ?? '',
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
