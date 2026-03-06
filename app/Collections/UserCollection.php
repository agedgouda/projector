<?php

namespace App\Collections;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class UserCollection extends Collection
{
    public function forInertia(): array
    {
        // Detect global super-admins via Spatie (team_id IS NULL)
        $superAdminIds = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('model_has_roles.model_id', $this->pluck('id'))
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('model_has_roles.team_id')
            ->where('roles.name', 'super-admin')
            ->pluck('model_has_roles.model_id')
            ->flip()
            ->all();

        $grouped = [];

        foreach ($this as $user) {
            $isSuper = isset($superAdminIds[$user->id]);

            if ($isSuper) {
                $grouped['System Administration'][] = $this->transformUser($user, null, null, true);

                continue;
            }

            if ($user->organizations->isEmpty()) {
                $grouped['Unassigned / External'][] = $this->transformUser($user, null, null, false);

                continue;
            }

            foreach ($user->organizations as $org) {
                $grouped[$org->name][] = $this->transformUser($user, $org->pivot->role, $org->id, false, $org->name);
            }
        }

        ksort($grouped);

        return $grouped;
    }

    protected function transformUser(User $user, ?string $role, ?string $orgId, bool $isSuper, ?string $orgName = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'roles' => $role ? [$role] : [],
            'is_super' => $isSuper,
            'organization_id' => $orgId,
            'organization_name' => $orgName ?? 'System Access',
            'row_key' => $user->id.'-'.($orgId ?? 'global'),
        ];
    }

    /**
     * Get a flattened list of organizations the users have access to,
     * formatted for project creation selects.
     */
    public function availableClients(): array
    {
        // Check if any user in this collection is a super-admin
        // (In the context of the Dashboard, this collection usually only contains the current user)
        $isSuperAdmin = $this->contains(fn ($user) => $user->hasRole('super-admin'));

        if ($isSuperAdmin) {
            return \App\Models\Organization::all()
                ->map(fn ($org) => [
                    'id' => $org->id,
                    'company_name' => $org->name,
                ])
                ->values()
                ->toArray();
        }

        // Default logic for standard users
        return $this->flatMap(fn ($user) => $user->organizations)
            ->unique('id')
            ->map(fn ($org) => [
                'id' => $org->id,
                'company_name' => $org->name,
            ])
            ->values()
            ->toArray();
    }
}
