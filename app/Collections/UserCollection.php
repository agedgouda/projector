<?php

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class UserCollection extends Collection
{
    public function forInertia(): array
    {
        // 1. Efficiently map roles per user AND per team
        $roleMap = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('model_has_roles.model_id', $this->pluck('id'))
            ->where('model_has_roles.model_type', User::class)
            ->select('model_has_roles.model_id', 'roles.name', 'model_has_roles.team_id')
            ->get();

        $grouped = [];

        foreach ($this as $user) {
            // Check if user is a global Super Admin
            $isSuper = $roleMap->where('model_id', $user->id)->contains('name', 'super-admin');

            if ($isSuper) {
                $grouped['System Administration'][] = $this->transformUser($user, $roleMap, null);
                continue;
            }

            if ($user->organizations->isEmpty()) {
                $grouped['Unassigned / External'][] = $this->transformUser($user, $roleMap, null);
                continue;
            }

            foreach ($user->organizations as $org) {
                $grouped[$org->name][] = $this->transformUser($user, $roleMap, $org->id, $org->name);
            }
        }

        ksort($grouped);
        return $grouped;
    }

    protected function transformUser($user, $roleMap, $orgId, $orgName = null): array
    {
        $contextRoles = $roleMap->where('model_id', $user->id)
            ->where('team_id', $orgId)
            ->pluck('name')
            ->toArray();

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'avatar'            => $user->avatar,
            'roles'             => $contextRoles,
            'is_super'          => $roleMap->where('model_id', $user->id)->contains('name', 'super-admin'),
            'organization_id'   => $orgId,
            'organization_name' => $orgName ?? 'System Access',
            'row_key'           => $user->id . '-' . ($orgId ?? 'global'),
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
        $isSuperAdmin = $this->contains(fn($user) => $user->hasRole('super-admin'));

        if ($isSuperAdmin) {
            return \App\Models\Organization::all()
                ->map(fn($org) => [
                    'id' => $org->id,
                    'company_name' => $org->name,
                ])
                ->values()
                ->toArray();
        }

        // Default logic for standard users
        return $this->flatMap(fn($user) => $user->organizations)
            ->unique('id')
            ->map(fn($org) => [
                'id' => $org->id,
                'company_name' => $org->name,
            ])
            ->values()
            ->toArray();
    }
}
