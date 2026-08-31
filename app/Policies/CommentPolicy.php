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

    /**
     * Unlike delete() above, no org-admin override — moderating (removing) another
     * member's comment is one thing, but rewriting it and leaving it attributed to them
     * is another. Only the author may edit their own comment.
     */
    public function update(User $user, Comment $comment): bool
    {
        return $user->id === $comment->user_id;
    }
}
