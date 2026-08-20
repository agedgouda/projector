<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreTaskListImportRequest extends FormRequest
{
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'original_filename' => ['nullable', 'string', 'max:255'],
            'headers' => ['required', 'array'],
            'headers.*' => ['nullable', 'string'],
            // Capped well above any realistic spreadsheet a person fills out by hand — this
            // whole import runs synchronously in one request (see TaskListImportController),
            // so the limit exists to keep that request bounded, not to reject real usage.
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
            'mapping' => ['required', 'array'],
            'mapping.name' => ['required', 'string'],
            'mapping.priority' => ['nullable', 'string'],
            'mapping.task_status' => ['nullable', 'string'],
            'mapping.due_at' => ['nullable', 'string'],
            'mapping.assignee' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mapping.name.required' => 'Choose which column contains the task name before importing.',
        ];
    }
}
