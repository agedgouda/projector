<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    use HandlesOrgPermissions;

    /**
     * The author, or an admin of the org that owns the commented task/document — never an
     * admin of some other org.
     */
    public function delete(User $user, Comment $comment): bool
    {
        if ($user->id === $comment->user_id) {
            return true;
        }

        $client = $comment->commentable?->project?->client;

        return $client !== null && $this->isOrgAdmin($user, $client);
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
