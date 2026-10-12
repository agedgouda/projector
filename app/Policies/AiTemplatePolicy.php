<?php

namespace App\Policies;

use App\Models\AiTemplate;
use App\Models\ProjectType;
use App\Models\User;

class AiTemplatePolicy
{
    use HandlesOrgPermissions;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AiTemplate $template): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isOrgAdmin($user);
    }

    public function duplicate(User $user): bool
    {
        return $this->isOrgAdmin($user);
    }

    public function update(User $user, AiTemplate $template): bool
    {
        return $this->isOrgAdmin($user)
            && $template->organization_id === getPermissionsTeamId();
    }

    /**
     * Turning a transformation on or off for the current organization — org admins, and
     * super-admins via before(). Which transformations can be turned off at all is
     * AiTemplate::isToggleableFor(), checked separately since before() would skip it here.
     */
    public function toggle(User $user, AiTemplate $template): bool
    {
        return $this->isOrgAdmin($user);
    }

    public function delete(User $user, AiTemplate $template): bool
    {
        if (! $this->isOrgAdmin($user) || $template->organization_id !== getPermissionsTeamId()) {
            return false;
        }

        $isInUse = ProjectType::query()
            ->whereJsonContains('workflow', ['ai_template_id' => $template->id])
            ->exists();

        return ! $isInUse;
    }
}
