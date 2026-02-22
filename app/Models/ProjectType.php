<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProjectType extends Model
{
    use HasFactory, HasUuids;

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
     * Get keys that should appear in the Document Manager (Hierarchy).
     */
    public function getDocumentationKeys(): array
    {
        return collect($this->document_schema)
            ->where('is_task', false)
            ->pluck('key')
            ->all();
    }

    /**
     * Get keys that should appear in the Kanban Board.
     */
    public function getTaskKeys(): array
    {
        return collect($this->document_schema)
            ->where('is_task', true)
            ->pluck('key')
            ->all();
    }
}
