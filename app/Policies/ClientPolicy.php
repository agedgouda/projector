<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    use HandlesOrgPermissions;

    public function view(User $user, Client $client): bool
    {
        // If the client belongs to the organization currently active in Wayfinder
        return $client->organization_id === getPermissionsTeamId();
    }

    public function create(User $user): bool
    {
        // Checks if user has 'org-admin' role for the currently set Team ID
        return $this->isOrgAdmin($user);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->isOrgAdmin($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->isOrgAdmin($user, $client);
    }
}
