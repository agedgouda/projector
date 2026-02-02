<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\Log;

trait HandlesOrgPermissions
{
    public function before(User $user, $ability)
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }
    }

    protected function isOrgAdmin(User $user, $model = null): bool
    {
        $activeTeamId = getPermissionsTeamId();

        // We check the database for ANY role named 'org-admin'
        $hasRole = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('roles.name', 'org-admin')
            ->where('model_has_roles.team_id', $activeTeamId)
            ->exists();

        // Logic for viewAny vs Model instance
        if (!$model) {
            return $hasRole;
        }

        if ($model instanceof User) {
            $isSameOrg = $model->organizations()
                ->where('organizations.id', $activeTeamId)
                ->exists();
            return $isSameOrg && $hasRole;
        }

        $targetTeamId = $model->organization_id ?? $activeTeamId;

        // Final check for non-user models
        return $hasRole && ($targetTeamId === $activeTeamId);
    }
}
