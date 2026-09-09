<?php

use App\Http\Controllers\Dropbox\EventsController;
use Illuminate\Support\Facades\Route;

// Dropbox calls this directly (no user session), so it sits outside the auth-protected route
// files and is authenticated instead via VerifyDropboxSignature (HMAC over the app secret) —
// see bootstrap/app.php for the matching CSRF exemption. Both the GET verification handshake
// and the POST notifications share one URL, matching how Dropbox itself only ever registers one.
Route::match(['get', 'post'], '/dropbox/events', [EventsController::class, 'handle'])
    ->middleware('dropbox.signature')
    ->name('dropbox.events');
