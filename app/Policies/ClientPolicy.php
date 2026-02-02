<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    /**
     * Determine if the user can view the client.
     */
    public function view(User $user, Client $client): bool
    {
        // 1. Platform-wide Super Admin
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // 2. Organization Admin
        // Can view any client belonging to their active organization
        if ($user->hasRole('org-admin') && $client->organization_id === getPermissionsTeamId()) {
            return true;
        }

        // 3. Organization Member / Consultant
        // Can only view if it's in their org AND they are explicitly attached via the pivot
        return $client->organization_id === getPermissionsTeamId() &&
               $user->clients()->where('clients.id', $client->id)->exists();
    }

    /**
     * Determine if the user can update the client.
     */
    public function update(User $user, Client $client): bool
    {
        // Super Admins or the Admin of the specific Organization
        return $user->hasRole('super-admin') ||
               ($user->hasRole('org-admin') && $client->organization_id === getPermissionsTeamId());
    }

    /**
     * Determine if the user can delete the client.
     */
    public function delete(User $user, Client $client): bool
    {
        // Usually, we keep deletion restricted to the same level as update
        return $user->hasRole('super-admin') ||
               ($user->hasRole('org-admin') && $client->organization_id === getPermissionsTeamId());
    }

    /**
     * Determine if the user can create clients.
     */
    public function create(User $user): bool
    {
        // Anyone with Admin status (Global or Org-level) can create a new client
        return $user->hasRole(['super-admin', 'org-admin']);
    }
}
