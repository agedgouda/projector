<?php

namespace App\Services;

use App\Models\DocumentTypeDefinition;
use App\Models\Project;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DocumentTypeResolver
{
    /**
     * Resolves an import picker's choice to a real, valid type key — never trusting the
     * frontend's `type` string directly, since it's user-editable request data. Shared by
     * every import source (Google Doc, uploaded file, picked meeting recording) so "intake vs.
     * everything else" is decided identically no matter where the content came from. A new
     * label creates (or, if the same label was already used by an earlier import, reuses) an
     * org-scoped DocumentTypeDefinition.
     *
     * @return array{type: string}
     */
    public function resolve(Project $project, ?string $type, ?string $newTypeLabel): array
    {
        $organizationId = $project->client?->organization_id;
        $intakeKey = config('workflow.intake_key');
        abort_unless(is_string($intakeKey), Response::HTTP_INTERNAL_SERVER_ERROR, 'workflow.intake_key is misconfigured.');

        if (filled($newTypeLabel)) {
            $label = trim($newTypeLabel);
            $key = Str::slug($label, '_');

            abort_if($key === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid type name.');

            $existingMaxOrder = DocumentTypeDefinition::where('organization_id', $organizationId)->max('order');
            $maxOrder = is_int($existingMaxOrder) ? $existingMaxOrder : 0;

            $definition = DocumentTypeDefinition::firstOrCreate(
                ['organization_id' => $organizationId, 'key' => $key],
                [
                    'label' => $label,
                    'is_task' => false,
                    'order' => $maxOrder + 1,
                ]
            );

            return ['type' => $definition->key];
        }

        // Matches the frontend's own source of truth (see visibleDocumentTypeKeys() in
        // resources/js/lib/documentTypes.ts): any type already used by a document in this
        // project is valid here, not just what's in the org's curated document_schema —
        // that catalog and "what this project actually has" are two different lists (a type
        // can easily exist on real documents — e.g. one this same resolver created earlier via
        // new_type_label — without ever being added to it). The intake type is always valid
        // too, even for a project with none yet, so a brand new project isn't stuck with only
        // "Other". Same "documents, not tasks or events" scope as the Documentation tab's own
        // tree (see useDocumentTree.ts) — this only ever resolves types for that tab.
        $catalog = DocumentTypeDefinition::catalogForOrganization($organizationId);
        $isTaskType = function (string $key) use ($catalog): bool {
            $definition = $catalog->get($key);

            return $definition !== null && $definition->is_task;
        };

        // No type specified at all (as opposed to an explicit, invalid one) defaults to
        // intake — the same default every caller of this resolver already assumes when it
        // has nothing more specific to say.
        if ($type === null) {
            return ['type' => $intakeKey];
        }

        $usedTypes = $project->documents()
            ->select('type')
            ->distinct()
            ->pluck('type')
            ->reject(fn (string $key) => $key === 'event' || $isTaskType($key))
            ->push($intakeKey);

        abort_unless($usedTypes->contains($type), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid document type.');

        return ['type' => $type];
    }
}
