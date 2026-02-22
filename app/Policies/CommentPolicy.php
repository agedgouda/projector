<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    use HandlesOrgPermissions;

    public function delete(User $user, Comment $comment): bool
    {
        return $user->id === $comment->user_id || $this->isOrgAdmin($user);
    }
}
