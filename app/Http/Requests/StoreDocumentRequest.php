<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project && Gate::check('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name' => [($isUpdate ? 'sometimes' : 'required'), 'string', 'max:255'],
            'type' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'content' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'priority' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'task_status' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'due_at' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
