<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;

class DocumentPolicy
{
    use HandlesOrgPermissions; // This enables the before() bypass for Super Admins

    /**
     * Determine if the user can view any documents.
     */
    public function viewAny(User $user): bool
    {
        // For non-super-admins, we check if they are at least an org-admin
        // or have basic access to the current team context.
        return $this->isOrgAdmin($user);
    }

    /**
     * Helper to verify if the user has access to the project's parent hierarchy.
     */
    private function canAccessProject(User $user, Project $project): bool
    {
        // NOTE: The Super Admin check is now handled automatically by the trait's before() method.

        // 1. Organization Check (Security Wall)
        // Ensure the project belongs to the organization currently active in the session
        if ($project->client->organization_id !== getPermissionsTeamId()) {
            return false;
        }

        // 2. Role-based check
        // Use the trait helper for a more robust check
        if ($this->isOrgAdmin($user, $project)) {
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
        return $this->canAccessProject($user, $document->project);
    }
}
