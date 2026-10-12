<?php

namespace App\Http\Requests;

use App\Models\AiTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Turns a transformation on or off for the viewer's current organization (see
 * AiTemplatePolicy::toggle()).
 */
class UpdateAiTemplateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('aiTemplate');

        return $template instanceof AiTemplate && Gate::check('toggle', $template);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.required' => 'Choose whether to turn this transformation on or off.',
            'enabled.boolean' => 'Choose whether to turn this transformation on or off.',
        ];
    }
}
