<?php

namespace App\Models;

use App\Collections\ProjectCollection; // You'll create this class
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'project_type_id',
        'client_id',
        'document_id',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * 1. Register the Custom Collection
     */
    public function newCollection(array $models = []): ProjectCollection
    {
        return new ProjectCollection($models);
    }

    /**
     * 2. Helper for single-model loading (used in show)
     */
    public function loadFullPipeline(): self
    {
        return $this->newCollection([$this])->withFullPipeline()->first();
    }

    /**
     * Get the client that owns the project.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the documents associated with the project.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id');
    }

    /**
     * Get the type that this project belongs to.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeVisibleTo($query, $user)
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->whereHas('client.users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });
    }

    /**
     * Get only the documentation documents (non-tasks).
     */
    public function getDocumentationPipe()
    {
        $keys = $this->type->getDocumentationKeys();

        return $this->documents
            ->whereIn('type', $keys)
            ->sortBy('type')
            ->values();
    }

    /**
     * Get documents grouped for Kanban (tasks).
     */
    public function getKanbanPipe()
    {
        $keys = $this->type->getTaskKeys();

        return $this->documents
            ->whereIn('type', $keys)
            ->groupBy('type');
    }
}
