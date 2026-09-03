<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesImportTransformationOwnership;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApplyTextImportTransformationRequest extends FormRequest
{
    use ValidatesImportTransformationOwnership;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project && Gate::check('create', [Document::class, $project]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Each entry in `passes` is the text-source counterpart to
     * ApplyImportTransformationRequest's mapping-based pass — an extraction_rule instead of a
     * column mapping, since a text source has no columns to resolve against.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'original_filename' => ['nullable', 'string', 'max:255'],
            // Generous but bounded — this is substituted whole into a prompt (see
            // TextExtractionService::extract()), so the cap exists to keep that call's cost and
            // latency bounded, not to reject any realistic single document.
            'text' => ['required', 'string', 'max:100000'],
            'ai_template_id' => [
                'nullable',
                Rule::exists('ai_templates', 'id')->where('type', 'text_import'),
            ],
            'passes' => ['required', 'array', 'min:1'],
            'passes.*.list_type' => ['required', 'string', 'in:task,event'],
            'passes.*.extraction_rule' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addOwnershipRule($validator);
    }
}
