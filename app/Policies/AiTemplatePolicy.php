<?php

namespace App\Policies;

use App\Models\{User, AiTemplate, ProjectType};

class AiTemplatePolicy
{
    public function before(User $user)
    {
        // Global admin check
        if (!$user->hasRole('admin')) {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AiTemplate $template): bool
    {
        return true;
    }

    public function delete(User $user, AiTemplate $template): bool
    {
        // Using the standard Laravel JSON helper for maximum safety
        $isInUse = ProjectType::query()
            ->whereJsonContains('workflow', ['ai_template_id' => $template->id])
            ->exists();

        return !$isInUse;
    }
}
