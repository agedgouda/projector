<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Universal check: Does the user belong to the client that owns this document?
     */
    private function belongsToUserClient(User $user, Document $document): bool
    {
        if ($user->hasRole('admin')) return true;

        // Ensure the document's project belongs to one of the user's clients
        return $user->clients()
            ->where('clients.id', $document->project->client_id)
            ->exists();
    }

    public function view(User $user, Document $document)
    {
        return $this->belongsToUserClient($user, $document);
    }

    public function update(User $user, Document $document)
    {
        return $this->belongsToUserClient($user, $document);
    }

    public function delete(User $user, Document $document)
    {
        return $this->belongsToUserClient($user, $document);
    }
}
