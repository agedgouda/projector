<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('project.{id}', function ($user, $id) {
    return true;
});

Broadcast::channel('organization.{id}', function ($user, $id) {
    return $user->organizations()->where('organizations.id', $id)->exists();
});
