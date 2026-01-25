<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Universal check: Does the user belong to the client that owns the project?
     */
    private function canAccessProject(User $user, string $clientId): bool
    {
        if ($user->hasRole('admin')) return true;

        // Ensure the project's client_id matches one of the user's clients
        return $user->clients()
            ->where('clients.id', $clientId)
            ->exists();
    }

    public function view(User $user, Document $document): bool
    {
        return $this->canAccessProject($user, $document->project->client_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->canAccessProject($user, $project->client_id);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->canAccessProject($user, $document->project->client_id);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->canAccessProject($user, $document->project->client_id);
    }
}
