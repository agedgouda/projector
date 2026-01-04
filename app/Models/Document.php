<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use \Illuminate\Database\Eloquent\Relations\HasOne;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Document extends Model
{
    use HasNeighbors;
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'type',
        'content',
        'metadata',
        'processed_at',
        'embedding'
    ];

    /**
     * Get the project associated with this document.
     */
    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        // We use belongsTo because the 'project_id' column is in THIS table (documents)
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Scope to find documents most similar to the provided vector.
     */
    public function scopeNearestNeighbors(Builder $query, array $vector, int $limit = 5): void
    {
        // Convert the PHP array to a format PostgreSQL understands
        $vectorString = '[' . implode(',', $vector) . ']';

        // '<=>' is the pgvector operator for Cosine Distance
        $query->orderByRaw("embedding <=> ?::vector", [$vectorString])
              ->limit($limit);
    }

    public function scopeVisibleTo($query, $user)
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Reach: Document -> Project -> Client -> User Pivot
        return $query->whereHas('project.client.users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });
    }
}
