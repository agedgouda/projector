<?php

namespace App\Http\Requests;

use App\Models\DemoSession;
use App\Rules\TurnstileChallenge;
use App\Services\Demo\DemoTracker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a demo sandbox from the /demo page.
 */
class StartDemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Required only when a secret key is configured, matching registration (CreateNewUser).
        return [
            'cf-turnstile-response' => config('services.turnstile.secret_key')
                ? ['required', new TurnstileChallenge($this->ip())]
                : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cf-turnstile-response.required' => 'Please complete the verification check.',
        ];
    }

    /**
     * A failed verification check is a visitor turned away, so it's counted on their demo session.
     */
    protected function failedValidation(Validator $validator): void
    {
        app(DemoTracker::class)->rejected(DemoSession::REJECTED_VERIFICATION);

        parent::failedValidation($validator);
    }
}
