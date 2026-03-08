<?php

namespace App\Policies;

use App\Models\User;

trait HandlesOrgPermissions
{
    /**
     * This runs before any other policy methods.
     */
    public function before(User $user, $ability)
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }
    }

    protected function isOrgMember(User $user, $model = null): bool
    {
        $activeTeamId = getPermissionsTeamId();

        if (! $activeTeamId) {
            return false;
        }

        $isMember = $user->organizations()
            ->where('organizations.id', $activeTeamId)
            ->exists();

        if (! $model) {
            return $isMember;
        }

        $targetTeamId = match (true) {
            $model instanceof \App\Models\Organization => $model->id,
            default => $model->organization_id ?? $activeTeamId,
        };

        return $isMember && ($targetTeamId === $activeTeamId);
    }

    protected function isOrgAdmin(User $user, $model = null): bool
    {
        $activeTeamId = getPermissionsTeamId();

        if (! $activeTeamId) {
            return false;
        }

        $hasRole = $user->organizations()
            ->where('organizations.id', $activeTeamId)
            ->wherePivot('role', 'org-admin')
            ->exists();

        if (! $model) {
            return $hasRole;
        }

        $targetTeamId = match (true) {
            $model instanceof \App\Models\Organization => $model->id,
            default => $model->organization_id ?? $activeTeamId,
        };

        return $hasRole && ($targetTeamId === $activeTeamId);
    }
}
