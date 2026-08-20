<?php

namespace App\Models;

use App\Collections\ProjectCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
        $url = $this->getFirstMediaUrl('logo');

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

    /**
     * Tasks whose home board is a *different* project but are also shown on this board's
     * Kanban (see Document::linkedProjects() for the inverse side). Kept separate from
     * documents() rather than merged into one relation so callers can tell native vs.
     * cross-posted cards apart — see getKanbanDocuments(), which is where the two get
     * combined for actual board rendering.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Document, $this>
     */
    public function linkedDocuments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_project_links')->withTimestamps();
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
     * The top-level project of this project's subproject family — itself if it has no
     * parent, or its parent otherwise. Nesting is capped at 2 levels throughout this app
     * (see ProjectRequest's parent_id validation, which refuses to let a project that
     * already has a parent become a parent itself), so a subproject's parent is always
     * itself top-level — no need to walk up more than one level.
     */
    public function familyRoot(): self
    {
        return $this->parent ?? $this;
    }

    /**
     * This project's subproject family: its top-level project plus that project's direct
     * children (itself included, whichever side of the family this project is on). Used to
     * scope which boards a task is allowed to move to or also be shown on — see
     * DocumentController::move()/updateBoards(). Distinct from
     * ReportController::projectIdsIncludingChildren(), which assumes the project it's
     * called on is already top-level (true for every existing caller, but not a safe
     * assumption for a task that's currently sitting on a subproject).
     *
     * @return array<int, string>
     */
    public function familyProjectIds(): array
    {
        $root = $this->familyRoot();
        $root->loadMissing('children:id,parent_id');

        return [(string) $root->id, ...$root->children->pluck('id')->map(fn ($id) => (string) $id)];
    }

    /**
     * Whether this project's Kanban columns are the same set (by `key`, order-independent)
     * as another project's — the rule that keeps a task's single task_status meaningful
     * everywhere it's shown once it can appear on more than one board (see
     * DocumentController::move()/updateBoards()). Compared by `key`, not `id`: `key` is the
     * frozen, stable identifier for a column (see the kanban_columns migration), while `id`
     * is just an autoincrement primary key with no meaning across two different projects'
     * column sets.
     */
    public function hasMatchingKanbanColumns(self $other): bool
    {
        $ownKeys = $this->kanbanColumns->pluck('key')->sort()->values();
        $otherKeys = $other->kanbanColumns->pluck('key')->sort()->values();

        return $ownKeys->all() === $otherKeys->all();
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
     * Get task documents for this project enriched with type_label from the catalog —
     * this board's own tasks plus any tasks whose home board is elsewhere in this
     * project's subproject family but are also shown here (see
     * Document::linkedProjects()/linkedDocuments() above). documentTypeCatalog() is
     * organization-scoped (not per-project), so it's safe to reuse for both: every member
     * of a subproject family shares the same client, hence the same organization.
     */
    public function getKanbanDocuments(): \Illuminate\Support\Collection
    {
        $catalog = $this->documentTypeCatalog();

        $native = $this->documents
            ->filter(fn (Document $doc) => $this->isTaskType($catalog, $doc->type))
            ->map(fn (Document $doc) => array_merge($doc->toArray(), [
                'type_label' => $this->labelForType($catalog, $doc->type),
                'is_linked' => false,
            ]));

        // Guarded against a stale link back to this project's own id (shouldn't be
        // possible — DocumentController::updateBoards() never allows linking a task to its
        // own home project — but native rows should win if it ever happened anyway).
        $linked = $this->linkedDocuments
            ->filter(fn (Document $doc) => $doc->project_id !== $this->id && $this->isTaskType($catalog, $doc->type))
            ->map(fn (Document $doc) => array_merge($doc->toArray(), [
                'type_label' => $this->labelForType($catalog, $doc->type),
                'is_linked' => true,
                'home_project_id' => $doc->project_id,
                'home_project_name' => $doc->project?->name,
            ]));

        return $native->concat($linked)->values();
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
