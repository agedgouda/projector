<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait HandlesOrgPermissions
{
    /**
     * This runs before any other policy methods.
     */
    public function before(User $user, $ability)
    {
        // Force a check that ignores the current team scoping
        // This ensures a Global Super Admin is recognized even inside an Org context
        if ($user->hasRole('super-admin')) {
            return true;
        }
    }

    protected function isOrgAdmin(User $user, $model = null): bool
    {
        $activeTeamId = getPermissionsTeamId();

        // If no team is active, regular users fail, but 'before()' already handled Super Admins
        if (!$activeTeamId) {
            return false;
        }

        // Standard check: Does the user have the role for THIS specific team?
        $hasRole = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->where('roles.name', 'org-admin')
            ->where('model_has_roles.team_id', $activeTeamId)
            ->exists();

        if (!$model) return $hasRole;

        // Ensure the model belongs to the organization currently being viewed
        $targetTeamId = match(true) {
            $model instanceof \App\Models\Organization => $model->id,
            default => $model->organization_id ?? $activeTeamId,
        };

        return $hasRole && ($targetTeamId === $activeTeamId);
    }
}
