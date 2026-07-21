<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string|null $organization_id
 * @property array $document_schema
 */
class ProjectType extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'icon',
        'is_template',
        'document_schema',
        'workflow',
        'organization_id',
    ];

    protected $casts = [
        'document_schema' => 'array',
        'workflow' => 'array',
        'is_template' => 'boolean',
    ];

    protected function documentSchema(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $schema = json_decode($value ?? '[]', true);

                if (! $schema) {
                    return [];
                }

                return array_map(function ($item) {
                    $item['plural_label'] = Str::plural($item['label'] ?? '');

                    return $item;
                }, $schema);
            }
        );
    }

    /**
     * Get the organization this project type belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get all projects associated with this type.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get lifecycle steps for this project type, ordered by position.
     */
    public function lifecycleSteps(): HasMany
    {
        return $this->hasMany(LifecycleStep::class)->orderBy('order');
    }

    /**
     * Get the normalized workflow steps for this project type, ordered by position.
     *
     * Not yet read anywhere — this is the "expand" step of migrating workflow off JSON.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function workflowSteps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('order');
    }
}
