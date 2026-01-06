<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ProjectType extends Model
{
    use HasUuids; // Ensures the ID is generated as a UUID automatically

    protected $fillable = [
        'name',
        'icon',
        'document_schema',
        'workflow',
    ];

    protected $casts = [
        'document_schema' => 'array',
        'workflow' => 'array',
    ];

    protected function documentSchema(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $schema = json_decode($value, true);

                if (!$schema) return [];

                return array_map(function ($item) {
                    // We add a new 'plural_label' key so we don't break
                    // any existing logic that relies on the singular 'label'
                    $item['plural_label'] = Str::plural($item['label'] ?? '');
                    return $item;
                }, $schema);
            }
        );
    }

    /**
     * Get all projects associated with this type.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
