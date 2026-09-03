<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesImportTransformationOwnership;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApplyImportTransformationRequest extends FormRequest
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
     * Each entry in `passes` is exactly the shape StoreTaskListImportRequest already validates
     * for a single-type import (list_type + mapping) — running N of them is what turns one
     * sheet into records of N different types, each still fully separate (see ImportTaskList,
     * unchanged) rather than one hybrid record.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'original_filename' => ['nullable', 'string', 'max:255'],
            'headers' => ['required', 'array'],
            'headers.*' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
            'ai_template_id' => [
                'nullable',
                Rule::exists('ai_templates', 'id')->where('type', 'spreadsheet_import'),
            ],
            'passes' => ['required', 'array', 'min:1'],
            'passes.*.list_type' => ['required', 'string', 'in:task,event'],
            'passes.*.mapping' => ['required', 'array'],
            'passes.*.mapping.name' => ['required', 'string'],
            'passes.*.mapping.priority' => ['nullable', 'string'],
            'passes.*.mapping.task_status' => ['nullable', 'string'],
            'passes.*.mapping.due_at' => ['nullable', 'string'],
            'passes.*.mapping.assignee' => ['nullable', 'string'],
            'passes.*.mapping.start_date' => ['nullable', 'string'],
            'passes.*.mapping.description' => ['nullable', 'string'],
            'passes.*.mapping.tag' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addOwnershipRule($validator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'passes.*.mapping.name.required' => 'Choose which column contains the name for every pass before importing.',
        ];
    }
}
