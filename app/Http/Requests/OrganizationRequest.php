<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OrganizationRequest extends FormRequest
{
    protected function prepareForValidation()
    {
        $organization = $this->route('organization');

        if ($organization instanceof Organization) {
            setPermissionsTeamId($organization->id);
        }

        $this->merge([
            'slug' => Str::slug($this->name),
            'normalized_name' => Organization::normalize($this->name),
        ]);
    }

    public function authorize(): bool
    {
        $organization = $this->route('organization');
        return $organization
            ? Gate::allows('update', $organization)
            : Gate::allows('create', Organization::class);
    }

    public function rules(): array
    {
        $organization = $this->route('organization');
        $slug = $this->slug;
        $normalized = $this->normalized_name;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($organization, $slug, $normalized) {
                    // Look for the conflicting record
                    $conflict = Organization::where(function ($query) use ($slug, $normalized) {
                            $query->where('slug', $slug)
                                  ->orWhere('normalized_name', $normalized);
                        })
                        ->when($organization, function ($query) use ($organization) {
                            $query->where('id', '!=', $organization->id);
                        })
                        ->first(['name']); // We only need the name for the error message

                    if ($conflict) {
                        $fail("An organization the name '{$conflict->name}' already exists. Please choose a different name.");
                    }
                },
            ],
            'slug' => 'required|string',
            'normalized_name' => 'required|string',
        ];
    }
}
