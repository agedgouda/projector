<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    use HandlesOrgPermissions;

    public function viewAny(User $user): bool
    {
        return $this->isOrgAdmin($user);
    }

    public function update(User $user, User $targetUser): bool
    {
        // Still allow Org Admins to manage users,
        // but keep the specific "don't edit yourself" rule
        if ($user->id === $targetUser->id) return false;

        return $this->isOrgAdmin($user);
    }
}
