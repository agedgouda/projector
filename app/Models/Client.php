<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'company_name',
        'contact_name',
        'contact_phone',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

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
        // Any user (org-admin, org-member, etc.) sees all clients
        // belonging to the current organization context.
        return $query->where('organization_id', getPermissionsTeamId());
    }
}
