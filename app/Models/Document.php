<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property Project|null $project
 * @property User|null $creator
 * @property User|null $editor
 * @property User|null $assignee
 * @property OrganizationInvitation|null $pendingAssignee
 * @property string|null $locked_project_type_id
 * @property string|null $custom_prompt
 * @property int|null $last_ai_template_id
 * @property string|null $last_output_key
 * @property AiTemplate|null $lastAiTemplate
 * @property \Illuminate\Support\Carbon|null $content_updated_at
 */
class Document extends Model implements HasMedia
{
    use HasNeighbors, HasUuids, InteractsWithMedia;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'content_updated_at' => 'datetime',
        'creator_id' => 'integer',
        'editor_id' => 'integer',
        'assignee_id' => 'integer',
        'pending_assignee_invitation_id' => 'integer',
        'last_ai_template_id' => 'integer',
    ];

    protected $hidden = ['embedding'];

    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'type',
        'content',
        'metadata',
        'processed_at',
        'content_updated_at',
        'embedding',
        'creator_id',
        'editor_id',
        'assignee_id',
        'pending_assignee_invitation_id',
        'task_status',
        'priority',
        'due_at',
        'external_due_at',
        'locked_project_type_id',
        'custom_prompt',
        'last_ai_template_id',
        'last_output_key',
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

    /**
     * Boards this task is *also* shown on, beyond its one home board (project()/project_id).
     * See DocumentController::updateBoards() for the family/matching-columns rules that
     * constrain which projects can appear here.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function linkedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'document_project_links')->withTimestamps();
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

    public function pendingAssignee(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvitation::class, 'pending_assignee_invitation_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Document, $this>
     */
    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Document::class, 'parent_id');
    }

    /**
     * The AI template that most recently generated this document's children — via the
     * universal intake step, a locked protocol's workflow step, or a manually chosen Transform.
     * Lets Reprocess re-run "whatever last produced output from this document" even for types
     * with no single, unambiguous next step of their own (see ProjectAiService::process()).
     *
     * @return BelongsTo<AiTemplate, $this>
     */
    public function lastAiTemplate(): BelongsTo
    {
        return $this->belongsTo(AiTemplate::class, 'last_ai_template_id');
    }

    /**
     * The protocol this document's downstream lineage is locked to, once processed via a
     * user-chosen protocol (as opposed to a raw AI template, or the universal intake step).
     *
     * @return BelongsTo<ProjectType, $this>
     */
    public function lockedProjectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'locked_project_type_id');
    }

    /**
     * The workflow step (if any) that the locked protocol defines for this document's own
     * type. Its absence means this document is a terminal deliverable within its locked
     * protocol — reprocessing it would never produce a new child.
     *
     * Relies on a correlated subquery (whereColumn against this table), so it only works
     * through `withExists()`/`withCount()`/`has()` — never via `load()` or lazy access.
     *
     * @return HasOne<WorkflowStep, $this>
     */
    public function lockedNextWorkflowStep(): HasOne
    {
        return $this->hasOne(WorkflowStep::class, 'project_type_id', 'locked_project_type_id')
            ->whereColumn('workflow_steps.from_key', 'documents.type');
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

    /**
     * Source audio for a mobile-recorded note, pending transcription. Kept on the private
     * 'local' disk (never public) — recorded meeting audio is sensitive and only ever needs
     * to be streamed back to its owner via an authenticated route, not linked directly.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('recording')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes([
                'audio/mpeg',
                'audio/mp4',
                'audio/x-m4a',
                'audio/aac',
                'audio/wav',
                'audio/x-wav',
                'audio/webm',
            ]);
    }
}
