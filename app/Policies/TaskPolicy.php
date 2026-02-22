<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    use HandlesOrgPermissions;

    public function view(User $user, Task $task): bool
    {
        $project = $task->project;

        if ($project->client->organization_id !== getPermissionsTeamId()) {
            return false;
        }

        if ($this->isOrgAdmin($user, $project)) {
            return true;
        }

        return $user->clients()->where('clients.id', $project->client_id)->exists();
    }
}
