<?php

namespace App\Policies;

use App\Models\ProjectType;
use App\Models\User;

class ProjectTypePolicy
{
    use HandlesOrgPermissions;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isOrgAdmin($user);
    }

    public function update(User $user, ProjectType $type): bool
    {
        return $this->isOrgAdmin($user, $type);
    }

    public function delete(User $user, ProjectType $type): bool
    {
        return $this->isOrgAdmin($user, $type)
            && $type->projects_count === 0;
    }

    public function duplicate(User $user, ProjectType $type): bool
    {
        if (! $this->isOrgAdmin($user)) {
            return false;
        }

        // Org-admins can only copy global types or their own org's types
        return $type->organization_id === null
            || $type->organization_id === getPermissionsTeamId();
    }
}
