<?php

namespace App\Models;

use App\Collections\ProjectCollection;
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
        'description_quality',
        'project_type_id',
        'client_id',
        'document_id',
        'current_lifecycle_step_id',
    ];

    // Explicitly define the primary key type for UUIDs
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Register the Custom Collection for Pipeline logic.
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
     * Get the type that this project belongs to.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
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

    /**
     * Get task documents for this project enriched with type_label from the schema.
     */
    public function getKanbanDocuments(): \Illuminate\Support\Collection
    {
        $schema = collect($this->type?->document_schema ?? [])->keyBy('key');
        $taskKeys = $this->type?->getTaskKeys() ?? [];

        return $this->documents
            ->whereIn('type', $taskKeys)
            ->map(fn ($doc) => array_merge($doc->toArray(), [
                'type_label' => $schema->get($doc->type)['label'] ?? $doc->type,
            ]))
            ->values();
    }
}
