<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanColumn extends Model
{
    /** @use HasFactory<\Database\Factories\KanbanColumnFactory> */
    use HasFactory;

    protected $fillable = ['project_id', 'key', 'label', 'color', 'order'];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<int, array{key: string, label: string, color: string, order: int}>
     */
    public static function defaultDefinitions(): array
    {
        return [
            ['key' => 'todo', 'label' => 'To Do', 'color' => 'slate', 'order' => 1],
            ['key' => 'in_progress', 'label' => 'In Progress', 'color' => 'red', 'order' => 2],
            ['key' => 'review', 'label' => 'In Review', 'color' => 'amber', 'order' => 3],
            ['key' => 'done', 'label' => 'Done', 'color' => 'emerald', 'order' => 4],
        ];
    }

    public static function seedDefaultsFor(Project $project): void
    {
        foreach (self::defaultDefinitions() as $definition) {
            $project->kanbanColumns()->create($definition);
        }
    }
}
