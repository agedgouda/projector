<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function before(User $user)
    {
        // Only admins can manage users/permissions
        if (!$user->hasRole('admin')) return false;
    }

    public function viewAny(User $user) { return true; }
    public function update(User $user, User $model)
    {
        // Example: Prevent admins from accidentally de-ranking themselves
        return $user->id !== $model->id;
    }
}
