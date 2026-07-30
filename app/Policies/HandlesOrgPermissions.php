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

    /**
     * With a $model, checks membership in that resource's own organization — regardless of
     * which org happens to be "active" in the viewer's session/cookie, since a user can
     * legitimately belong to more than one org and the active one is just a UI default, not
     * an access boundary. Without a $model, falls back to checking the active org itself
     * (used for non-resource-scoped actions like "can create a project in my current org").
     */
    protected function isOrgMember(User $user, $model = null): bool
    {
        $targetTeamId = $this->resolveTargetTeamId($model);

        if (! $targetTeamId) {
            return false;
        }

        return $user->organizations()
            ->where('organizations.id', $targetTeamId)
            ->exists();
    }

    /**
     * @see isOrgMember() — same resource-org-over-active-org rule applies here.
     */
    protected function isOrgAdmin(User $user, $model = null): bool
    {
        $targetTeamId = $this->resolveTargetTeamId($model);

        if (! $targetTeamId) {
            return false;
        }

        return $user->organizations()
            ->where('organizations.id', $targetTeamId)
            ->wherePivot('role', 'org-admin')
            ->exists();
    }

    private function resolveTargetTeamId($model = null)
    {
        if (! $model) {
            return getPermissionsTeamId();
        }

        return match (true) {
            $model instanceof \App\Models\Organization => $model->id,
            default => $model->organization_id ?? getPermissionsTeamId(),
        };
    }
}
