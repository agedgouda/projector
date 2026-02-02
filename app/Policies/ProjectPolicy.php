<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine if the user can view the project list.
     */
    public function viewAny(User $user): bool
    {
        // Allow access to the index; the Model Scope (visibleTo) will handle the filtering.
        return true;
    }

    /**
     * Determine if the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        // 1. Super Admin: Always yes (handled by Gate::before if you added it, but good to keep here)
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // 2. Org Admin: Can see any project belonging to a client in their organization
        if ($user->hasRole('org-admin') && $project->client->organization_id === getPermissionsTeamId()) {
            return true;
        }

        // 3. Org Member / Consultant: Can only see if they are explicitly attached to the parent client
        return $project->client->organization_id === getPermissionsTeamId() &&
               $user->clients()->where('clients.id', $project->client_id)->exists();
    }

    /**
     * Determine if the user can create projects.
     */
    public function create(User $user): bool
    {
        // Global admins and Organization admins can create projects
        return $user->hasRole(['super-admin', 'org-admin']);
    }

    /**
     * Determine if the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        // Use the same logic as view - if they can view it as an admin, they can edit it.
        // If you want members to edit, add them here.
        return $user->hasRole('super-admin') ||
               ($user->hasRole('org-admin') && $project->client->organization_id === getPermissionsTeamId());
    }

    /**
     * Determine if the user can delete/restore projects.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasRole('super-admin') ||
               ($user->hasRole('org-admin') && $project->client->organization_id === getPermissionsTeamId());
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }
}
