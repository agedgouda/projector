<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    protected function prepareForValidation()
    {
        $organization = $this->route('organization');

        if ($organization instanceof Organization) {
            setPermissionsTeamId($organization->id);
        }

        if ($this->name) {
            $this->merge([
                'slug' => Str::slug($this->name),
                'normalized_name' => Organization::normalize($this->name),
            ]);
        }
    }

    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization
            ? Gate::allows('update', $organization)
            : Gate::allows('create', Organization::class);
    }

    public function rules(): array
    {
        $organization = $this->route('organization');
        $slug = $this->name ? Str::slug($this->name) : '';
        $normalized = $this->name ? Organization::normalize($this->name) : '';

        return [
            'name' => [
                $organization ? 'sometimes' : 'required', 'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($organization, $slug, $normalized) {
                    $conflict = Organization::where(function ($query) use ($slug, $normalized) {
                        $query->where('slug', $slug)
                            ->orWhere('normalized_name', $normalized);
                    })
                        ->when($organization, fn ($q) => $q->where('id', '!=', $organization->id))
                        ->first(['name']);

                    if ($conflict) {
                        $fail("An organization with the name '{$conflict->name}' already exists.");
                    }
                },
            ],
            'website' => 'nullable|url|max:255',
            'uses_external_due_dates' => 'boolean',
            'uses_task_start_dates' => 'boolean',

            // Driver Validation
            'llm_driver' => 'nullable|string',
            'vector_driver' => 'nullable|string',

            // ADD THESE TWO LINES:
            'llm_config' => 'nullable|array',
            'vector_config' => 'nullable|array',

            // Nested Config Validation
            'llm_config.model' => 'nullable|required_with:llm_driver|string',
            'llm_config.host' => 'nullable|string',
            'llm_config.key' => [Rule::requiredIf(fn (): bool => $this->needsOwnGeminiKey('llm', $organization)), 'nullable', 'string'],

            'vector_config.model' => 'nullable|required_with:vector_driver|string',
            'vector_config.host' => 'nullable|string',
            'vector_config.key' => [Rule::requiredIf(fn (): bool => $this->needsOwnGeminiKey('vector', $organization)), 'nullable', 'string'],

            // Meeting Provider
            'meeting_provider' => 'nullable|string|in:zoom,teams,google_meet,slack',
            'meeting_config' => 'nullable|array',
            'meeting_config.account_id' => 'nullable|string',
            'meeting_config.tenant_id' => 'nullable|string',
            'meeting_config.client_id' => 'nullable|string',
            'meeting_config.client_secret' => 'nullable|string',
            'meeting_config.service_account_email' => 'nullable|email',
            'meeting_config.impersonate_email' => 'nullable|email',
            'meeting_config.private_key' => 'nullable|string',
            'meeting_config.bot_token' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'llm_config.key.required' => 'Google Gemini requires your own API key.',
            'vector_config.key.required' => 'Google Gemini requires your own API key.',
        ];
    }

    /**
     * Gemini has no shared platform key (see config/services.php), so choosing it requires the
     * organization's own key — either submitted now or already stored from an earlier save.
     *
     * @param  'llm'|'vector'  $type
     */
    private function needsOwnGeminiKey(string $type, mixed $organization): bool
    {
        if ($this->input("{$type}_driver") !== 'gemini') {
            return false;
        }

        $storedConfig = $organization instanceof Organization && $organization->{"{$type}_driver"} === 'gemini'
            ? $organization->{"{$type}_config"}
            : null;

        return ! filled($storedConfig['key'] ?? null);
    }
}
