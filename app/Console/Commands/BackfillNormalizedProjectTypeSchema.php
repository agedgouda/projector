<?php

namespace App\Console\Commands;

use App\Models\AiTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\ProjectType;
use App\Services\DocumentTypeCatalogResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillNormalizedProjectTypeSchema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backfill-normalized-project-type-schema {--dry-run : Report what would change without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate the shared document_type_definitions catalog and per-protocol workflow_steps from document_schema/workflow JSON. Safe to re-run.';

    public function __construct(private readonly DocumentTypeCatalogResolver $catalogResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $validAiTemplateIds = AiTemplate::query()->get(['id'])->map(fn (AiTemplate $t): int => $t->id)->all();

        $projectTypes = ProjectType::query()->orderBy('created_at')->orderBy('id')->get();

        [$winnersByGroup, $collisions] = $this->catalogResolver->resolveCatalog($projectTypes);
        $definitionCounts = $this->syncDocumentTypeDefinitions($winnersByGroup, $dryRun);

        $stepResults = [];
        foreach ($projectTypes as $projectType) {
            $stepResults[] = $this->syncWorkflowSteps($projectType, $dryRun, $validAiTemplateIds);
        }
        $stepsWritten = array_sum(array_column($stepResults, 'written'));
        $danglingReferences = array_merge([], ...array_column($stepResults, 'dangling'));

        $this->info(sprintf(
            '%sDocument type definitions: %d created, %d updated, %d deleted (stale). Workflow steps rewritten for %d project type(s) with a workflow.',
            $dryRun ? '[DRY RUN] ' : '',
            $definitionCounts['created'],
            $definitionCounts['updated'],
            $definitionCounts['deleted'],
            $stepsWritten,
        ));

        if (! empty($collisions)) {
            Log::warning('Document type definition collisions found during backfill', ['collisions' => $collisions]);
            $this->warn(sprintf('%d document type key collision(s) found — first-seen value kept, logged for manual review:', count($collisions)));
            foreach ($collisions as $collision) {
                $this->warn(sprintf('  - key "%s" (org=%s):', $collision['key'], $collision['organization_id'] ?? 'global'));
                $this->warn(sprintf('      kept:      "%s" -> label=%s, is_task=%s', $collision['winning_project_type'], $collision['winning_label'], $collision['winning_is_task'] ? 'true' : 'false'));
                $this->warn(sprintf('      conflicts: "%s" -> label=%s, is_task=%s', $collision['conflicting_project_type'], $collision['conflicting_label'], $collision['conflicting_is_task'] ? 'true' : 'false'));
            }
        }

        if (! empty($danglingReferences)) {
            Log::warning('Dangling ai_template_id references found during backfill', ['dangling' => $danglingReferences]);
            $this->warn(sprintf('%d workflow step(s) reference an ai_template_id that no longer exists — normalized with ai_template_id set to null:', count($danglingReferences)));
            foreach ($danglingReferences as $ref) {
                $this->warn(sprintf('  - ProjectType "%s" (%s):', $ref['project_type_name'], $ref['project_type_id']));
                $this->warn(sprintf('      %s -> %s, missing ai_template_id=%s', $ref['from_key'], $ref['to_key'], $ref['ai_template_id']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Upsert document_type_definitions rows in each org group (a '__global__' sentinel key
     * represents the null/shared group) to match the resolved catalog, and remove any rows for
     * keys no longer produced by any current protocol in that group. Safe to re-run.
     *
     * @param  array<string, array<string, array{label: string, is_task: bool, order: int, source: string}>>  $winnersByGroup
     * @return array{created: int, updated: int, deleted: int}
     */
    private function syncDocumentTypeDefinitions(array $winnersByGroup, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $deleted = 0;

        foreach ($winnersByGroup as $groupKey => $winners) {
            $organizationId = $groupKey === '__global__' ? null : $groupKey;
            $existingRows = DocumentTypeDefinition::query()->where('organization_id', $organizationId)->get()->keyBy('key');

            foreach ($winners as $key => $winner) {
                $attributes = ['label' => $winner['label'], 'is_task' => $winner['is_task'], 'order' => $winner['order']];
                $row = $existingRows->get($key);

                if (! $row) {
                    $created++;
                    if (! $dryRun) {
                        DocumentTypeDefinition::create($attributes + ['key' => $key, 'organization_id' => $organizationId]);
                    }

                    continue;
                }

                $isDifferent = $row->label !== $attributes['label']
                    || $row->is_task !== $attributes['is_task']
                    || $row->order !== $attributes['order'];

                if ($isDifferent) {
                    $updated++;
                    if (! $dryRun) {
                        $row->update($attributes);
                    }
                }
            }

            $staleKeys = $existingRows->keys()->diff(array_keys($winners));
            $deleted += $staleKeys->count();

            if ($staleKeys->isNotEmpty() && ! $dryRun) {
                DocumentTypeDefinition::query()->where('organization_id', $organizationId)->whereIn('key', $staleKeys)->delete();
            }
        }

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
    }

    /**
     * Rewrite workflow_steps to match the ProjectType's workflow JSON exactly. Unlike document
     * schema keys, workflow steps have no natural unique key (duplicate from_key/to_key pairs are
     * technically possible), so this replaces the full set per project type inside a transaction
     * rather than diffing row-by-row. Safe to re-run — always converges to the current JSON.
     *
     * A step whose ai_template_id no longer exists in ai_templates (a dangling reference that JSON
     * storage allowed but a real FK will not) is written with ai_template_id = null and reported,
     * rather than aborting the whole run.
     *
     * @param  array<int>  $validAiTemplateIds
     * @return array{written: int, dangling: array<int, array{project_type_id: string, project_type_name: string, from_key: string, to_key: string, ai_template_id: int}>}
     */
    private function syncWorkflowSteps(ProjectType $projectType, bool $dryRun, array $validAiTemplateIds): array
    {
        $workflow = $this->catalogResolver->workflowItems($projectType);

        if (empty($workflow)) {
            if (! $dryRun) {
                $projectType->workflowSteps()->delete();
            }

            return ['written' => 0, 'dangling' => []];
        }

        $dangling = [];

        foreach ($workflow as $step) {
            $aiTemplateId = $step['ai_template_id'] ?? null;
            if ($aiTemplateId !== null && ! in_array($aiTemplateId, $validAiTemplateIds, true)) {
                $dangling[] = [
                    'project_type_id' => $projectType->id,
                    'project_type_name' => $projectType->name,
                    'from_key' => $step['from_key'],
                    'to_key' => $step['to_key'],
                    'ai_template_id' => $aiTemplateId,
                ];
            }
        }

        if ($dryRun) {
            return ['written' => 1, 'dangling' => $dangling];
        }

        DB::transaction(function () use ($projectType, $workflow, $validAiTemplateIds) {
            $projectType->workflowSteps()->delete();

            foreach ($workflow as $index => $step) {
                $aiTemplateId = $step['ai_template_id'] ?? null;
                if ($aiTemplateId !== null && ! in_array($aiTemplateId, $validAiTemplateIds, true)) {
                    $aiTemplateId = null;
                }

                $projectType->workflowSteps()->create([
                    'from_key' => $step['from_key'],
                    'to_key' => $step['to_key'],
                    'ai_template_id' => $aiTemplateId,
                    'single_output' => $step['single_output'],
                    'order' => $index + 1,
                ]);
            }
        });

        return ['written' => 1, 'dangling' => $dangling];
    }
}
