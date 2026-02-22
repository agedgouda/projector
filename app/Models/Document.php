<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Document extends Model
{
    use HasNeighbors, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'creator_id' => 'integer',
        'editor_id' => 'integer',
        'assignee_id' => 'integer',
    ];

    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'type',
        'content',
        'metadata',
        'processed_at',
        'embedding',
        'creator_id',
        'editor_id',
        'assignee_id',
        'task_status',
        'priority',
        'due_at',
    ];

    /**
     * Re-introducing the booted method for automatic ID assignment.
     * Note: We use auth()->check() to ensure this only fires when a user is present.
     */
    protected static function booted()
    {
        static::creating(function ($document) {
            if (auth()->check()) {
                $document->creator_id = $document->creator_id ?? auth()->id();
                $document->editor_id = $document->editor_id ?? auth()->id();
            }
        });

        static::updating(function ($document) {
            if (auth()->check()) {
                $document->editor_id = auth()->id();
            }
        });
    }

    /**
     * Relationships
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Scopes
     */
    // public function scopeNearestNeighbors(Builder $query, array $vector, int $limit = 5): void
    // {
    //     $vectorString = '[' . implode(',', $vector) . ']';
    //     $query->orderByRaw("embedding <=> ?::vector", [$vectorString])
    //           ->limit($limit);
    // }

    public function scopeNearestNeighbors(Builder $query, array|Vector $vector, int $limit = 5): void
    {
        // The trait 'HasNeighbors' already gives you 'nearestNeighbors'
        // But if you use your manual one, ensure the string conversion is safe
        $query->nearestNeighbors('embedding', $vector)->limit($limit);
    }

    public function scopeVisibleTo(Builder $query, $user)
    {
        if ($user->hasRole('super-admin')) {
            return $query;
        }

        return $query->whereHas('project.client.users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });
    }

    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Task::class, 'document_id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->oldest();
    }
}
