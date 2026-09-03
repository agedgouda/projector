<?php

namespace App\Http\Requests\Concerns;

use App\Models\AiTemplate;
use App\Models\Project;
use Illuminate\Validation\Validator;

/**
 * Shared by ApplyImportTransformationRequest (spreadsheet) and ApplyTextImportTransformationRequest
 * (text) — a saved transformation's own organization must match the project's, once resolved.
 * Needs the route's Project, so it can't be a plain `exists` rule in rules().
 */
trait ValidatesImportTransformationOwnership
{
    protected function addOwnershipRule(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $templateId = $this->input('ai_template_id');
            if ($templateId === null) {
                return;
            }

            $project = $this->route('project');
            $template = AiTemplate::find($templateId);
            if (! $project instanceof Project || ! $template instanceof AiTemplate) {
                return;
            }

            $organizationId = $project->client?->organization_id;

            if ($template->organization_id !== null && $template->organization_id !== $organizationId) {
                $validator->errors()->add('ai_template_id', 'That saved transformation does not belong to this project\'s organization.');
            }
        });
    }
}
