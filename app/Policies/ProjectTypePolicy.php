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
            && $type->projects_count === 0
            && ! $type->projects()->exists();
    }
}
