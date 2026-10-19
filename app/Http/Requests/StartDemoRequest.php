<?php

namespace App\Http\Requests;

use App\Rules\TurnstileChallenge;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
}
