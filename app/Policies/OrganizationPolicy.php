<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Determine if a user can see the organization settings/dashboard.
     */
    public function view(User $user, Organization $organization): bool
    {
        // Super Admin sees all.
        if ($user->hasRole('super-admin')) return true;

        // Regular users can only "view" the org they are currently switched into.
        return $organization->id === getPermissionsTeamId();
    }

    /**
     * Determine if a user can edit the organization (name, settings, etc).
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->hasRole('super-admin') ||
               ($user->hasRole('org-admin') && $organization->id === getPermissionsTeamId());
    }

    /**
     * Determine if a user can invite or remove users from this organization.
     */
    public function manageUsers(User $user, Organization $organization): bool
    {
        return $user->hasRole('super-admin') ||
               ($user->hasRole('org-admin') && $organization->id === getPermissionsTeamId());
    }

    /**
     * Determine who can delete the entire organization.
     */
    public function delete(User $user, Organization $organization): bool
    {
        // Usually restricted to Super Admin to prevent accidental company-wide data loss.
        return $user->hasRole('super-admin');
    }
}
