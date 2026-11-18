<?php

namespace App\Models;

use App\Enums\CustomPromptMode;
use App\Services\RecordingIntakeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
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
 * @property string|null $custom_prompt
 * @property CustomPromptMode|null $custom_prompt_mode
 * @property int|null $last_ai_template_id
 * @property string|null $last_output_key
 * @property AiTemplate|null $lastAiTemplate
 * @property Carbon|null $content_updated_at
 * @property Carbon|null $status_changed_at
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
        'status_changed_at' => 'datetime',
        'creator_id' => 'integer',
        'editor_id' => 'integer',
        'assignee_id' => 'integer',
        'pending_assignee_invitation_id' => 'integer',
        'last_ai_template_id' => 'integer',
        'processing_triggered_by_user_id' => 'integer',
        'custom_prompt_mode' => CustomPromptMode::class,
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
        'processing_triggered_by_user_id',
        'task_status',
        'priority',
        'due_at',
        'external_due_at',
        'start_at',
        'predecessor_id',
        'custom_prompt',
        'custom_prompt_mode',
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

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_id');
    }

    /**
     * The task this one waits on — it starts the day that task ends (see TaskChainScheduler).
     *
     * @return BelongsTo<Document, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'predecessor_id');
    }

    /**
     * The tasks that start the day this one ends.
     *
     * @return HasMany<Document, $this>
     */
    public function followers(): HasMany
    {
        return $this->hasMany(Document::class, 'predecessor_id');
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

    public function scopeVisibleTo(Builder $query, $user)
    {
        if ($user->hasRole('super-admin')) {
            return $query;
        }

        return $query->whereHas('project.client.users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'document_id');
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->oldest();
    }

    /**
     * Users who have opened this document at least once — drives the per-user unread dot on
     * an async-imported transcript (Documentation tab and its row in the tree). Only ever
     * written to from DocumentController::show(), and only ever grows.
     *
     * @return BelongsToMany<User, $this>
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_reads')->withTimestamps();
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
            ->acceptsMimeTypes(explode(',', RecordingIntakeService::RECORDING_MIMES));
    }
}
