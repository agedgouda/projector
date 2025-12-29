<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectType extends Model
{
    use HasUuids; // Ensures the ID is generated as a UUID automatically

    protected $fillable = [
        'name',
        'icon',
        'document_schema',
    ];

    protected $casts = [
        'document_schema' => 'array',
    ];

    /**
     * Get all projects associated with this type.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
