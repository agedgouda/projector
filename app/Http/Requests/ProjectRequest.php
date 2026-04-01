<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 1. Get the Project instance if this is an update/delete request
        $project = $this->route('project');

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
            'project_type_id' => 'sometimes|required|exists:project_types,id',
            'client_id' => $this->isMethod('POST') ? 'required|exists:clients,id' : 'sometimes|required|exists:clients,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Project Name Missing',
            'client_id.required' => 'You must select a client for this project.',
            'client_id.exists' => 'You must select a client for this project.',
            'project_type_id.required' => 'You must select a protocol for this project.',
            'project_type_id.exists' => 'You must select a protocol for this project.',
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
