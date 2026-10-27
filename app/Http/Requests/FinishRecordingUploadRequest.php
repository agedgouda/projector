<?php

namespace App\Http\Requests;

use App\Enums\CustomPromptMode;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * An uploaded audio/video file (one whole-file tus upload, see
 * TusUploadController::finishUpload()) that's finished uploading and should become a
 * recording.
 */
class FinishRecordingUploadRequest extends FormRequest
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
            'upload_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'custom_prompt' => ['nullable', 'string'],
            'custom_prompt_mode' => ['nullable', Rule::enum(CustomPromptMode::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'upload_id.required' => 'No uploaded file was sent.',
            'upload_id.uuid' => 'The uploaded file could not be identified.',
            'name.required' => 'The recording needs a name.',
        ];
    }

    public function customPromptMode(): ?CustomPromptMode
    {
        $mode = $this->validated('custom_prompt_mode');

        return is_string($mode) ? CustomPromptMode::tryFrom($mode) : null;
    }
}
