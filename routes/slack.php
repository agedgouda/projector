<?php

use App\Http\Controllers\Slack\CommandsController;
use App\Http\Controllers\Slack\EventsController;
use App\Http\Controllers\Slack\InteractivityController;
use Illuminate\Support\Facades\Route;

// Slack calls these directly (no user session), so they sit outside the auth-protected route
// files and are authenticated instead via VerifySlackSignature (HMAC over the signing secret) —
// see bootstrap/app.php for the matching CSRF exemption.
Route::post('/slack/events', [EventsController::class, 'handle'])
    ->middleware('slack.signature')
    ->name('slack.events');

Route::post('/slack/commands', [CommandsController::class, 'handle'])
    ->middleware('slack.signature')
    ->name('slack.commands');

Route::post('/slack/interactivity', [InteractivityController::class, 'handle'])
    ->middleware('slack.signature')
    ->name('slack.interactivity');
