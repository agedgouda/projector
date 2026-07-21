<?php

namespace App\Services;

use App\Models\ProjectType;
use Illuminate\Support\Collection;

class DocumentTypeCatalogResolver
{
    /**
     * Extract well-formed document_schema items — anything missing a string 'key' is dropped
     * rather than crashing the caller, since JSON storage doesn't guarantee shape. label/is_task
     * are validated and defaulted here too, so every returned item has the full shape.
     *
     * @return array<int, array{key: string, label: string, is_task: bool}>
     */
    public function schemaItems(ProjectType $projectType): array
    {
        $items = [];

        foreach ((array) ($projectType->document_schema ?? []) as $item) {
            if (! is_array($item) || empty($item['key']) || ! is_string($item['key'])) {
                continue;
            }

            $items[] = [
                'key' => $item['key'],
                'label' => is_string($item['label'] ?? null) ? $item['label'] : $item['key'],
                'is_task' => (bool) ($item['is_task'] ?? false),
            ];
        }

        return $items;
    }

    /**
     * Extract well-formed workflow steps — anything missing a string from_key/to_key is dropped
     * rather than crashing the caller, since JSON storage doesn't guarantee shape. ai_template_id/
     * single_output are validated and defaulted here too, so every returned item has the full shape.
     *
     * @return array<int, array{from_key: string, to_key: string, ai_template_id: int|null, single_output: bool}>
     */
    public function workflowItems(ProjectType $projectType): array
    {
        $items = [];

        foreach ((array) ($projectType->workflow ?? []) as $step) {
            if (! is_array($step) || empty($step['from_key']) || empty($step['to_key'])
                || ! is_string($step['from_key']) || ! is_string($step['to_key'])) {
                continue;
            }

            $items[] = [
                'from_key' => $step['from_key'],
                'to_key' => $step['to_key'],
                'ai_template_id' => is_int($step['ai_template_id'] ?? null) ? $step['ai_template_id'] : null,
                'single_output' => (bool) ($step['single_output'] ?? false),
            ];
        }

        return $items;
    }

    /**
     * Compute the shared document_type_definitions catalog implied by every ProjectType's current
     * document_schema JSON, grouped by organization (a '__global__' sentinel key represents the
     * null/shared-across-all-orgs group, since Collection::groupBy doesn't group null cleanly).
     *
     * Protocols are processed in the order given — callers should pass them ordered by created_at
     * so the first protocol to define a given key within a group wins. Any later protocol defining
     * the same key with a different label/is_task is reported as a collision rather than silently
     * overwritten, so it can be reviewed and reconciled manually.
     *
     * @param  Collection<int, ProjectType>  $projectTypes
     * @return array{0: array<string, array<string, array{label: string, is_task: bool, order: int, source: string}>>, 1: array<int, array{organization_id: string|null, key: string, winning_project_type: string, winning_label: string, winning_is_task: bool, conflicting_project_type: string, conflicting_label: string, conflicting_is_task: bool}>}
     */
    public function resolveCatalog(Collection $projectTypes): array
    {
        $collisions = [];
        $winnersByGroup = [];

        $groups = $projectTypes->groupBy(fn (ProjectType $pt) => $pt->organization_id ?? '__global__');

        foreach ($groups as $groupKey => $group) {
            $organizationId = $groupKey === '__global__' ? null : (string) $groupKey;

            /** @var array<string, array{label: string, is_task: bool, order: int, source: string}> $winners */
            $winners = [];
            $order = 0;

            foreach ($group as $projectType) {
                foreach ($this->schemaItems($projectType) as $item) {
                    $key = $item['key'];
                    $candidate = [
                        'label' => $item['label'],
                        'is_task' => $item['is_task'],
                    ];

                    if (! isset($winners[$key])) {
                        $order++;
                        $winners[$key] = $candidate + ['order' => $order, 'source' => $projectType->name];

                        continue;
                    }

                    $existing = $winners[$key];
                    if ($existing['label'] !== $candidate['label'] || $existing['is_task'] !== $candidate['is_task']) {
                        $collisions[] = [
                            'organization_id' => $organizationId,
                            'key' => $key,
                            'winning_project_type' => $existing['source'],
                            'winning_label' => $existing['label'],
                            'winning_is_task' => $existing['is_task'],
                            'conflicting_project_type' => $projectType->name,
                            'conflicting_label' => $candidate['label'],
                            'conflicting_is_task' => $candidate['is_task'],
                        ];
                    }
                }
            }

            $winnersByGroup[$groupKey] = $winners;
        }

        return [$winnersByGroup, $collisions];
    }
}
