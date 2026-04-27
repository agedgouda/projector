<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreOrgDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization && Gate::check('create', [\App\Models\OrgDocument::class, $organization]);
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name' => [($isUpdate ? 'sometimes' : 'required'), 'string', 'max:255'],
            'type' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'content' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
