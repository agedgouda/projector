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

        // Standard role check for the active team
        $hasRole = \Illuminate\Support\Facades\DB::table('model_has_roles')
            // ... (your existing query) ...
            ->exists();

        if (!$model) return $hasRole;

        if ($model instanceof User) {
            return $model->organizations()->where('organizations.id', $activeTeamId)->exists() && $hasRole;
        }

        // NEW: Handle if the model IS the Organization
        if ($model instanceof \App\Models\Organization) {
            $targetTeamId = $model->id;
        } else {
            // Handle Projects, Clients, etc. via virtual attribute
            $targetTeamId = $model->organization_id ?? $activeTeamId;
        }

        return $hasRole && ($targetTeamId === $activeTeamId);
    }
}
