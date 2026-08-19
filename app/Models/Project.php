<?php

namespace App\Models;

use App\Collections\ProjectCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property Client|null $client
 * @property LifecycleTemplate|null $lifecycleTemplate
 * @property string|null $organization_id
 * @property string $id
 * @property bool $inactive
 * @property string|null $logo_url
 * @property \Illuminate\Database\Eloquent\Collection<int, Document> $documents
 */
class Project extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    protected $fillable = [
        'name',
        'description',
        'description_quality',
        'inactive',
        'lifecycle_template_id',
        'client_id',
        'document_id',
        'current_lifecycle_step_id',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'inactive' => 'boolean',
        ];
    }

    // Explicitly define the primary key type for UUIDs
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Falls back to the parent project's logo when this project has none of its own —
     * sub-projects are meant to look like part of the same project unless someone
     * deliberately uploads a distinct logo for them.
     */
    public function getLogoUrlAttribute(): ?string
    {
        $url = $this->getFirstMediaUrl('logo', 'preview');

        if ($url !== '') {
            return $url;
        }

        return $this->parent?->logo_url;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // keepOriginalImageFormat() is required here — Spatie MediaLibrary's Conversion class
        // defaults every conversion to JPEG regardless of the source format (see its own
        // Conversion::__construct(), which unconditionally calls ->format('jpg')). JPEG has no
        // alpha channel, so a transparent PNG logo gets flattened onto a solid canvas — and
        // GD's default fill for that canvas is black, not white. That's what was turning
        // transparent project logos solid black: the "preview"/"thumb" files were silently
        // being saved as .jpg, not .png. keepOriginalImageFormat() preserves whatever format
        // was actually uploaded (PNG stays PNG with alpha intact, WebP stays WebP, etc.).
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->keepOriginalImageFormat()
            ->width(150)
            ->height(150);

        $this->addMediaConversion('preview')
            ->nonQueued()
            ->keepOriginalImageFormat()
            ->width(400)
            ->height(400);
    }

    /**
     * Register the Custom Collection for Pipeline logic.
     *
     * @phpstan-ignore method.childReturnType
     */
    public function newCollection(array $models = []): ProjectCollection
    {
        return new ProjectCollection($models);
    }

    /**
     * Helper for single-model loading (used in show).
     */
    public function loadFullPipeline(): ?self
    {
        /** @var static|null */
        return $this->newCollection([$this])->withFullPipeline()->first();
    }

    public function getOrganizationIdAttribute()
    {
        $orgId = $this->client?->organization_id;

        if (! $orgId) {
            \Log::warning('[ModelDebug] Project could not reach Organization ID', [
                'project_id' => $this->id,
                'has_client' => (bool) $this->client,
            ]);
        }

        return $orgId;
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
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id');
    }

    public function dismissedRecordings(): HasMany
    {
        return $this->hasMany(DismissedRecording::class, 'project_id');
    }

    /**
     * Get the lifecycle (stage) template this project follows.
     *
     * @return BelongsTo<LifecycleTemplate, $this>
     */
    public function lifecycleTemplate(): BelongsTo
    {
        return $this->belongsTo(LifecycleTemplate::class);
    }

    /**
     * Get the current lifecycle step for this project.
     */
    public function currentLifecycleStep(): BelongsTo
    {
        return $this->belongsTo(LifecycleStep::class, 'current_lifecycle_step_id');
    }

    /**
     * Get the tasks associated with the project.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get the Kanban board columns for this project.
     *
     * @return HasMany<KanbanColumn, $this>
     */
    public function kanbanColumns(): HasMany
    {
        return $this->hasMany(KanbanColumn::class)->orderBy('order');
    }

    /**
     * Get the parent project, if this is a sub-project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_id');
    }

    /**
     * Get this project's sub-projects.
     *
     * @return HasMany<Project, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_id');
    }

    /**
     * Multi-tenant visibility scope.
     */
    public function scopeVisibleTo($query, User $user, ?string $orgId = null)
    {
        $currentOrgId = $orgId ?? getPermissionsTeamId();

        // 1. Super-Admin check requires null team context (the role has team_id = null).
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($currentOrgId);

        if ($isSuperAdmin) {
            if ($currentOrgId) {
                return $query->whereHas('client', fn ($q) => $q->where('organization_id', $currentOrgId));
            }

            return $query;
        }

        // 2. Fail-safe: If no org context is set, return no projects.
        if (! $currentOrgId) {
            return $query->whereRaw('1 = 0');
        }

        // 3. Org-admins and project-leads see all projects in the org.
        if ($user->organizations()->where('organizations.id', $currentOrgId)->wherePivotIn('role', ['org-admin', 'project-lead'])->exists()) {
            return $query->whereHas('client', fn ($q) => $q->where('organization_id', $currentOrgId));
        }

        // 4. Members / Consultants see only projects for their assigned clients.
        return $query->whereHas('client', function ($q) use ($user, $currentOrgId) {
            $q->where('organization_id', $currentOrgId)
                ->whereHas('users', fn ($sub) => $sub->where('users.id', $user->id));
        });
    }

    /**
     * The effective document-type catalog for this project's organization — the source of truth
     * for whether a document's type is a task, independent of which protocol produced it.
     *
     * @return \Illuminate\Support\Collection<string, DocumentTypeDefinition>
     */
    public function documentTypeCatalog(): \Illuminate\Support\Collection
    {
        return DocumentTypeDefinition::catalogForOrganization($this->organization_id);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, DocumentTypeDefinition>  $catalog
     */
    private function isTaskType(\Illuminate\Support\Collection $catalog, string $type): bool
    {
        $definition = $catalog->get($type);

        return $definition instanceof DocumentTypeDefinition && $definition->is_task;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, DocumentTypeDefinition>  $catalog
     */
    private function labelForType(\Illuminate\Support\Collection $catalog, string $type): string
    {
        $definition = $catalog->get($type);

        return $definition instanceof DocumentTypeDefinition ? $definition->label : $type;
    }

    /**
     * Get only the documentation documents (non-tasks). A document's type doesn't need to be
     * declared anywhere in advance — anything not flagged is_task in the catalog counts as
     * documentation, since documents can be produced by any protocol's recipe.
     */
    public function getDocumentationPipe()
    {
        $catalog = $this->documentTypeCatalog();

        return $this->documents
            ->filter(fn (Document $doc) => ! $this->isTaskType($catalog, $doc->type))
            ->sortBy('type')
            ->values();
    }

    /**
     * Get documents grouped for Kanban (tasks).
     */
    public function getKanbanPipe()
    {
        $catalog = $this->documentTypeCatalog();

        return $this->documents
            ->filter(fn (Document $doc) => $this->isTaskType($catalog, $doc->type))
            ->groupBy('type');
    }

    /**
     * Get task documents for this project enriched with type_label from the catalog.
     */
    public function getKanbanDocuments(): \Illuminate\Support\Collection
    {
        $catalog = $this->documentTypeCatalog();

        return $this->documents
            ->filter(fn (Document $doc) => $this->isTaskType($catalog, $doc->type))
            ->map(fn (Document $doc) => array_merge($doc->toArray(), [
                'type_label' => $this->labelForType($catalog, $doc->type),
            ]))
            ->values();
    }

    /**
     * Flattened due-date items for the calendar: this project's own documents plus
     * its direct sub-projects' documents (2-level cap — no grandchildren exist),
     * limited to documents that have at least one due date set.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, priority: string, task_status: string
     * }>
     */
    public function calendarItems(): \Illuminate\Support\Collection
    {
        /**
         * @var array<int, array{
         *     id: string, name: string|null, content: string|null, type: string,
         *     project_id: string, project_name: string, is_subproject: bool,
         *     due_at: string|null, external_due_at: string|null, priority: string, task_status: string
         * }> $items
         */
        $items = [];

        /** @var array<int, array{project: Project, is_subproject: bool}> $sources */
        $sources = [['project' => $this, 'is_subproject' => false]];

        foreach ($this->children as $child) {
            $sources[] = ['project' => $child, 'is_subproject' => true];
        }

        foreach ($sources as $source) {
            foreach ($source['project']->documents as $doc) {
                $items[] = [
                    'id' => $doc->id,
                    'name' => $doc->name,
                    'content' => $doc->content,
                    'type' => $doc->type,
                    'project_id' => $doc->project_id,
                    'project_name' => $source['project']->name,
                    'is_subproject' => $source['is_subproject'],
                    'due_at' => $doc->due_at,
                    'external_due_at' => $doc->external_due_at,
                    'priority' => $doc->priority,
                    'task_status' => $doc->task_status,
                ];
            }
        }

        return collect($items)
            ->filter(fn (array $item) => $item['due_at'] || $item['external_due_at'])
            ->values();
    }
}
