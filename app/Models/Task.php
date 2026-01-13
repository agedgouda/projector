<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids; // Important!
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasUuids; // This handles automatic UUID generation on create

    protected $fillable = [
        'project_id', 'assignee_id', 'document_id',
        'title', 'description', 'status', 'priority', 'due_at'
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
