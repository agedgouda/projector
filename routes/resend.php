<?php

use App\Http\Controllers\Resend\WebhookController;
use Illuminate\Support\Facades\Route;

// Resend calls this directly (no user session), so it's authenticated via VerifyResendSignature
// instead — see bootstrap/app.php for the matching CSRF exemption.
Route::post('/resend/webhook', [WebhookController::class, 'handle'])
    ->middleware('resend.signature')
    ->name('resend.webhook');
