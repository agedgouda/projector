<?php

namespace App\Policies;

use App\Models\{User, ProjectType};

class ProjectTypePolicy
{
    public function before(User $user)
    {
        // Only admins can touch the Library configuration
        if (!$user->hasRole('admin')) return false;
    }

    public function viewAny(User $user) { return true; }
    public function create(User $user) { return true; }
    public function update(User $user, ProjectType $type) { return true; }

    public function delete(User $user, ProjectType $type)
    {
        // "Tight" security check: Prevent breaking the system
        // if projects are already using this protocol.
        return $type->projects_count === 0 && !$type->projects()->exists();
    }

}
