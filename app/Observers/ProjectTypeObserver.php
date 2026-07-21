<?php

namespace App\Observers;

use App\Models\DocumentTypeDefinition;
use App\Models\ProjectType;
use Illuminate\Support\Facades\Log;

class ProjectTypeObserver
{
    /**
     * Keep the shared document type catalog in sync whenever a protocol's document_schema is
     * saved — via the admin UI, a factory, a seeder, or tinker, not just the controller. This is
     * additive/update-only: a key that's no longer in this protocol's schema is left in the
     * catalog untouched, since other protocols or already-created documents may still depend on
     * it (only the full `app:backfill-normalized-project-type-schema` command can safely decide a
     * key is unused by anything).
     *
     * A key already defined at the same scope (same organization_id) with a different
     * label/is_task is left as-is and logged, rather than silently overwritten.
     */
    public function saved(ProjectType $projectType): void
    {
        $schema = collect($projectType->document_schema ?? [])
            ->filter(fn ($item) => is_array($item) && ! empty($item['key']) && is_string($item['key']));

        if ($schema->isEmpty()) {
            return;
        }

        $existing = DocumentTypeDefinition::where('organization_id', $projectType->organization_id)->get()->keyBy('key');
        $nextOrder = ($existing->max('order') ?? 0) + 1;

        foreach ($schema as $item) {
            $key = $item['key'];
            $label = is_string($item['label'] ?? null) ? $item['label'] : $key;
            $isTask = (bool) ($item['is_task'] ?? false);

            $current = $existing->get($key);

            if (! $current) {
                DocumentTypeDefinition::create([
                    'organization_id' => $projectType->organization_id,
                    'key' => $key,
                    'label' => $label,
                    'is_task' => $isTask,
                    'order' => $nextOrder++,
                ]);

                continue;
            }

            if ($current->label !== $label || $current->is_task !== $isTask) {
                Log::warning('Document type definition collision on ProjectType save — existing catalog entry kept', [
                    'organization_id' => $projectType->organization_id,
                    'key' => $key,
                    'project_type_id' => $projectType->id,
                    'project_type_name' => $projectType->name,
                    'existing_label' => $current->label,
                    'existing_is_task' => $current->is_task,
                    'attempted_label' => $label,
                    'attempted_is_task' => $isTask,
                ]);
            }
        }
    }
}
