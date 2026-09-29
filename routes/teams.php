<?php

use App\Http\Controllers\Teams\MessagesController;
use Illuminate\Support\Facades\Route;

// The Bot Connector calls this directly (no user session), so it sits outside the auth-protected
// route files and is authenticated instead via VerifyTeamsToken (a Bot Framework-signed JWT) —
// see bootstrap/app.php for the matching CSRF exemption.
Route::post('/teams/messages', [MessagesController::class, 'handle'])
    ->middleware('teams.token')
    ->name('teams.messages');
