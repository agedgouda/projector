<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('project.{id}', function ($user, $id) {
    return true;
});
