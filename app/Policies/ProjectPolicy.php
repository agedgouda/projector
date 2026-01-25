<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Helper to check if user belongs to the client.
     */
    private function belongsToClient(User $user, string $clientId): bool
    {
        if ($user->hasRole('admin')) return true;

        // Querying exists() is faster than loading the whole collection into memory
        return $user->clients()
            ->where('clients.id', $clientId)
            ->exists();
    }

    public function viewAny(User $user): bool
    {
        // Typically true so they can see the list page,
        // but the list query itself should be scoped to their clients.
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $this->belongsToClient($user, $project->client_id);
    }

    public function create(User $user): bool
    {
        // If users can create projects, change to true or add role check
        return $user->hasRole('admin');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->belongsToClient($user, $project->client_id);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return $user->hasRole('admin');
    }
}
