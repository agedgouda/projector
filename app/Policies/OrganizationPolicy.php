<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    use HandlesOrgPermissions;

    /**
     * Can the user see the dashboard?
     */
    public function view(User $user, Organization $organization): bool
    {
        // Trait's before() handles Super Admin.
        // For others, ensure they belong to this org and it's their active context.
        return $user->organizations->contains($organization->id) &&
               $organization->id === getPermissionsTeamId();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Checks if user has 'org-admin' role for the currently set Team ID
        return $this->isOrgAdmin($user);
    }

    /**
     * Can they edit org settings?
     */
    public function update(User $user, Organization $organization): bool
    {
        // We pass the organization as the $model to isOrgAdmin
        return $this->isOrgAdmin($user, $organization);
    }

    public function manageUsers(User $user, Organization $organization): bool
    {
        return $this->isOrgAdmin($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->isOrgAdmin($user, $organization);
    }
}
