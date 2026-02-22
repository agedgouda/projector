<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        if ($client) {
            return Gate::check('update', $client);
        }

        return Gate::check('create', Client::class);
    }

    public function rules(): array
    {
        $teamId = getPermissionsTeamId();
        // Detect if we are updating by checking for the 'client' route parameter
        $client = $this->route('client');

        return [
            'company_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clients')
                    ->where(fn ($query) => $query->where('organization_id', $teamId))
                    ->ignore($client?->id),
            ],
            'contact_name' => 'required', 'string', 'max:255',
            'contact_phone' => 'required', 'string', 'max:20',
            'email' => 'nullable', 'email', 'max:255',
            'website' => 'nullable', 'url', 'max:255',
        ];
    }
}
