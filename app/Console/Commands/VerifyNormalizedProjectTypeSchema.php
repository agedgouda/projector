<?php

namespace App\Console\Commands;

use App\Models\AiTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\ProjectType;
use App\Services\DocumentTypeCatalogResolver;
use Illuminate\Console\Command;

class VerifyNormalizedProjectTypeSchema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:verify-normalized-project-type-schema';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare document_schema/workflow JSON against the backfilled document_type_definitions/workflow_steps tables and report any mismatch. Exits non-zero if anything differs.';

    public function __construct(private readonly DocumentTypeCatalogResolver $catalogResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $validAiTemplateIds = AiTemplate::query()->get(['id'])->map(fn (AiTemplate $t): int => $t->id)->all();
        $projectTypes = ProjectType::query()->with('workflowSteps')->orderBy('created_at')->orderBy('id')->get();

        [$winnersByGroup] = $this->catalogResolver->resolveCatalog($projectTypes);

        $mismatches = $this->diffDocumentTypeCatalog($winnersByGroup);

        foreach ($projectTypes as $projectType) {
            $mismatches = array_merge($mismatches, $this->diffWorkflow($projectType, $validAiTemplateIds));
        }

        if (empty($mismatches)) {
            $this->info('OK — normalized tables match document_schema/workflow JSON for every ProjectType.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d mismatch(es) found:', count($mismatches)));
        foreach ($mismatches as $mismatch) {
            $this->error('  - '.$mismatch);
        }

        return self::FAILURE;
    }

    /**
     * Compare the resolved catalog (what the backfill would produce right now) against what's
     * actually stored in document_type_definitions, per org group.
     *
     * @param  array<string, array<string, array{label: string, is_task: bool, order: int, source: string}>>  $winnersByGroup
     * @return array<int, string>
     */
    private function diffDocumentTypeCatalog(array $winnersByGroup): array
    {
        $mismatches = [];

        foreach ($winnersByGroup as $groupKey => $winners) {
            $organizationId = $groupKey === '__global__' ? null : $groupKey;
            $groupLabel = $organizationId === null ? 'global' : "organization {$organizationId}";
            $normalizedByKey = DocumentTypeDefinition::query()->where('organization_id', $organizationId)->get()->keyBy('key');

            if (count($winners) !== $normalizedByKey->count()) {
                $mismatches[] = "Catalog ({$groupLabel}): expected ".count($winners)." type(s) but document_type_definitions has {$normalizedByKey->count()}";
            }

            foreach ($winners as $key => $winner) {
                $row = $normalizedByKey->get($key);

                if (! $row) {
                    $mismatches[] = "Catalog ({$groupLabel}): key \"{$key}\" has no matching document_type_definitions row";

                    continue;
                }

                if ($row->label !== $winner['label'] || $row->is_task !== $winner['is_task'] || $row->order !== $winner['order']) {
                    $mismatches[] = "Catalog ({$groupLabel}): key \"{$key}\" differs from its document_type_definitions row";
                }
            }
        }

        return $mismatches;
    }

    /**
     * @param  array<int>  $validAiTemplateIds
     * @return array<int, string>
     */
    private function diffWorkflow(ProjectType $projectType, array $validAiTemplateIds): array
    {
        $jsonSteps = $this->catalogResolver->workflowItems($projectType);
        $normalizedSteps = $projectType->workflowSteps->sortBy('order')->values();
        $mismatches = [];
        $label = "ProjectType \"{$projectType->name}\" ({$projectType->id})";

        if (count($jsonSteps) !== $normalizedSteps->count()) {
            $mismatches[] = "{$label}: workflow has ".count($jsonSteps)." step(s) but workflow_steps has {$normalizedSteps->count()}";

            return $mismatches;
        }

        foreach ($jsonSteps as $index => $step) {
            $row = $normalizedSteps->get($index);
            $expectedAiTemplateId = $step['ai_template_id'] ?? null;

            // A dangling ai_template_id (present in JSON, deleted from ai_templates) is expected to
            // have been normalized to null by the backfill — that's not a mismatch here.
            if ($expectedAiTemplateId !== null && ! in_array($expectedAiTemplateId, $validAiTemplateIds, true)) {
                $expectedAiTemplateId = null;
            }

            $matches = $row
                && $row->from_key === $step['from_key']
                && $row->to_key === $step['to_key']
                && $row->ai_template_id === $expectedAiTemplateId
                && $row->single_output === $step['single_output'];

            if (! $matches) {
                $mismatches[] = "{$label}: workflow step at position {$index} (from_key=\"{$step['from_key']}\") differs from its workflow_steps row";
            }
        }

        return $mismatches;
    }
}
