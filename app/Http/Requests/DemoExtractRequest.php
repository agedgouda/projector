<?php

namespace App\Http\Requests;

use App\Rules\TurnstileChallenge;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A live run on the public demo page: meeting notes plus the date and team they refer to.
 */
class DemoExtractRequest extends FormRequest
{
    public const MIN_NOTES_LENGTH = 100;

    public const MAX_NOTES_LENGTH = 6000;

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
        $turnstileRules = config('services.turnstile.secret_key')
            ? ['required', new TurnstileChallenge($this->ip())]
            : [];

        return [
            'notes' => ['required', 'string', 'min:'.self::MIN_NOTES_LENGTH, 'max:'.self::MAX_NOTES_LENGTH],
            'meeting_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'team' => ['present', 'array', 'max:12'],
            'team.*' => ['required', 'string', 'max:60', 'distinct'],
            'cf-turnstile-response' => $turnstileRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Add some meeting notes or a transcript first.',
            'notes.min' => 'Add a bit more to the notes — at least '.self::MIN_NOTES_LENGTH.' characters.',
            'notes.max' => 'Notes can be up to '.number_format(self::MAX_NOTES_LENGTH).' characters in the demo.',
            'meeting_date.*' => 'Enter the meeting date.',
            'team.max' => 'The demo supports up to 12 team members.',
            'team.*.max' => 'Each team member name can be up to 60 characters.',
            'team.*.distinct' => 'Each team member can only be listed once.',
            'cf-turnstile-response.required' => 'Please complete the verification challenge.',
        ];
    }

    /**
     * @return list<string>
     */
    public function team(): array
    {
        $team = $this->validated('team');

        return is_array($team) ? array_values(array_filter(array_map(fn (mixed $name): string => is_string($name) ? trim($name) : '', $team))) : [];
    }
}
