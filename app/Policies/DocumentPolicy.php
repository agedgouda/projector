<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Helper to verify if the user has access to the project's parent hierarchy.
     */
    private function canAccessProject(User $user, Project $project): bool
    {
        // 1. Super Admin bypass
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // 2. Organization Check (Security Wall)
        // Ensure the project belongs to the organization currently active in the session
        if ($project->client->organization_id !== getPermissionsTeamId()) {
            return false;
        }

        // 3. Role-based check
        // Org Admins see all projects in their org.
        if ($user->hasRole('org-admin')) {
            return true;
        }

        // Members/Consultants must be explicitly attached to the Client
        return $user->clients()
            ->where('clients.id', $project->client_id)
            ->exists();
    }

    public function view(User $user, Document $document): bool
    {
        return $this->canAccessProject($user, $document->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->canAccessProject($user, $project);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->canAccessProject($user, $document->project);
    }

    public function delete(User $user, Document $document): bool
    {
        // Optional: You might want to restrict deleting documents to admins only
        return $this->canAccessProject($user, $document->project);
    }
}
