<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Stop on a browser-captured recording: the ids of every chunk the browser uploaded (see
 * TusUploadController::finish()).
 */
class FinishBrowserRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::check('create', [Document::class, $project]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'upload_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'upload_ids.*' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'upload_ids.required' => 'No recorded audio was sent.',
            'upload_ids.array' => 'No recorded audio was sent.',
            'upload_ids.min' => 'No recorded audio was sent.',
            'upload_ids.max' => 'This recording is too long to save in one piece.',
            'upload_ids.*.uuid' => 'Part of this recording could not be identified.',
        ];
    }

    /**
     * @return list<string>
     */
    public function uploadIds(): array
    {
        $ids = $this->validated('upload_ids');

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
