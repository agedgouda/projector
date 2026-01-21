<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    /**
     * Logic: A user can see a client only if they are an admin
     * or they are attached to that client.
     */
    public function view(User $user, Client $client): bool
    {
        if ($user->hasRole('admin')) return true;

        return $user->clients()->where('clients.id', $client->id)->exists();
    }

    /**
     * Typically only admins manage the actual Client entity.
     */
    public function update(User $user, Client $client): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->hasRole('admin');
    }
}
