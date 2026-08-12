<?php

namespace App\Http\Requests;

use App\Rules\ValidKanbanColumn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
     * Prepare the data for validation.
     *
     * `assignee_id` may arrive as a real user id, "unassigned", or an
     * "inv:{id}" string identifying a pending (not-yet-registered) invitee
     * (see DocumentSidebar.vue / KanbanCard.vue). The "inv:" form is split out
     * into `pending_assignee_invitation_id` here so `assignee_id` never reaches
     * the `exists:users,id` rule as a non-numeric string, mirroring
     * DocumentController::resolveAssignee() used by updateAttributes().
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('assignee_id') === 'unassigned') {
            $this->merge(['assignee_id' => null]);
        }

        if (! $this->has('assignee_id')) {
            return;
        }

        $rawAssignee = $this->input('assignee_id');

        if (is_string($rawAssignee) && str_starts_with($rawAssignee, 'inv:')) {
            $this->merge([
                'assignee_id' => null,
                'pending_assignee_invitation_id' => (int) substr($rawAssignee, 4),
            ]);
        } else {
            $this->merge(['pending_assignee_invitation_id' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $project = $this->route('project');

        return [
            'name' => [($isUpdate ? 'sometimes' : 'required'), 'string', 'max:255'],
            'type' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'content' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'priority' => [($isUpdate ? 'sometimes' : 'required'), 'string'],
            'task_status' => [($isUpdate ? 'sometimes' : 'required'), 'string', new ValidKanbanColumn($project?->id)],
            'due_at' => ['nullable', 'date'],
            'external_due_at' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'pending_assignee_invitation_id' => [
                'nullable',
                Rule::exists('organization_invitations', 'id')
                    ->where('organization_id', $project?->client?->organization_id),
            ],
            'metadata' => ['nullable', 'array'],
            'custom_prompt' => ['nullable', 'string'],
        ];
    }
}
