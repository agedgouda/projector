<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property Organization|null $organization
 * @property string $organization_id
 * @property bool $inactive
 * @property string|null $industry
 * @property \Illuminate\Database\Eloquent\Collection<int, Project> $projects
 */
class Client extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    protected $fillable = [
        'organization_id',
        'company_name',
        'contact_name',
        'contact_phone',
        'email',
        'industry',
        'inactive',
    ];

    protected function casts(): array
    {
        return [
            'inactive' => 'boolean',
        ];
    }

    protected $keyType = 'string';

    public $incrementing = false;

    public function getLogoUrlAttribute(): ?string
    {
        $url = $this->getFirstMediaUrl('logo', 'preview');

        return $url !== '' ? $url : null;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->width(150)
            ->height(150);

        $this->addMediaConversion('preview')
            ->nonQueued()
            ->width(400)
            ->height(400);
    }

    /**
     * Get the organization that owns the client.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Direct user associations (useful for Consultants or specific project access)
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Scope: Visibility logic for the new Hierarchy
     */
    public function scopeVisibleTo($query, User $user)
    {
        // 1. Global Super Admin sees everything across all orgs
        if ($user->hasRole('super-admin')) {
            return $query;
        }

        // 2. Organization Visibility
        // Any org user (org-admin, project-lead, team-member, etc.) sees all clients
        // belonging to the current organization context.
        return $query->where('organization_id', getPermissionsTeamId());
    }
}
