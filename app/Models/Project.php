<?php

namespace App\Models;

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

    public function scopeVisibleTo($query, $user)
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // A Project is visible if its PARENT CLIENT is visible to the user
        return $query->whereHas('client.users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });
    }

    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Task::class);
    }

}
