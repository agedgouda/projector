<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Change this to true or add your logic to check if user owns the project
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name'        => [($isUpdate ? 'sometimes' : 'required'), 'string', 'max:255'],
            'type'        => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'content'     => ['nullable', 'string'],
            'priority'    => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'task_status' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'due_at'      => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'metadata'    => ['nullable', 'array'],
        ];
    }
}
