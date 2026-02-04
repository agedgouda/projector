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

        $hasRole = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('roles.name', 'org-admin')
            ->where('model_has_roles.team_id', $activeTeamId)
            ->exists();

        if (!$model) {
            \Log::info('[PolicyDebug] No model check', ['has_role' => $hasRole]);
            return $hasRole;
        }

        if ($model instanceof User) {
            return $model->organizations()->where('organizations.id', $activeTeamId)->exists() && $hasRole;
        }
        $modelOrgId = $model->organization_id;
        $targetTeamId = $modelOrgId ?? $activeTeamId;

        return $hasRole && ($targetTeamId === $activeTeamId);
    }
}
