<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    use HandlesOrgPermissions;

    public function viewAny(User $user): bool
    {
        // Trait handles super-admin.
        // We return true and let Project::scopeVisibleTo handle the filtering.
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        // isOrgAdmin handles the comparison of $project->organization_id
        // (the virtual attribute) against the active team context.
        return $this->isOrgAdmin($user, $project);
    }

    public function create(User $user): bool
    {
        // Checks if user is an admin for the current context (set by ProjectRequest)
        return $this->isOrgAdmin($user);
    }

    public function update(User $user, Project $project): bool
    {
        // Clean and simple: let the trait do the work
        return $this->isOrgAdmin($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->isOrgAdmin($user, $project);
    }
}
