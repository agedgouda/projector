<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    private function routeProject(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }

    public function authorize(): bool
    {
        // 1. Get the Project instance if this is an update/delete request
        $project = $this->routeProject();

        // 2. Determine the Client ID
        // Use input if provided (store), otherwise use the existing project's client
        $clientId = $this->input('client_id') ?? $project?->client_id;

        // No client_id yet — let validation return the user-friendly error
        if (! $clientId) {
            return true;
        }

        // 3. Reach through to get the Org ID
        $client = \App\Models\Client::find($clientId);
        if (! $client) {
            return true;
        }

        // 4. Set the Spatie context
        setPermissionsTeamId($client->organization_id);

        // 5. Determine which ability to check
        $ability = $this->isMethod('POST') ? 'create' : 'update';

        // For 'create', we check the Class. For 'update', we check the specific Instance.
        return Gate::allows($ability, $project ?? Project::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            // Set by the frontend's own pre-submission AI evaluation (see
            // ProjectEntryForm.vue) so the badge is correct immediately, without
            // waiting on EvaluateProjectDescription's async follow-up job.
            'description_quality' => 'sometimes|nullable|in:good,vague',
            'inactive' => 'boolean',
            'client_id' => $this->isMethod('POST') ? 'required|exists:clients,id' : 'sometimes|required|exists:clients,id',
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('projects', 'id')->where(function ($query) {
                    $clientId = $this->input('client_id') ?? $this->routeProject()?->client_id;
                    $query->where('client_id', $clientId);
                }),
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }

                    $project = $this->routeProject();
                    if ($project && $value === $project->id) {
                        $fail('A project cannot be its own parent.');

                        return;
                    }

                    if ($project && $project->children()->exists()) {
                        $fail('This project has sub-projects and cannot become a sub-project itself.');

                        return;
                    }

                    $parent = Project::find((string) $value);
                    if ($parent instanceof Project && $parent->parent_id) {
                        $fail('That project is already a sub-project and cannot have its own sub-projects.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Project Name Missing',
            'client_id.required' => 'You must select a client for this project.',
            'client_id.exists' => 'You must select a client for this project.',
            'parent_id.exists' => 'The parent project must belong to the same client.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \Log::error('[ValidationDebug] ProjectRequest Failed', [
            'errors' => $validator->errors()->toArray(),
            'input' => $this->all(),
        ]);

        parent::failedValidation($validator);
    }
}
