<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Dropbox's request signature (https://www.dropbox.com/developers/reference/webhooks)
 * on every inbound Dropbox webhook notification before anything else touches the payload —
 * Dropbox's counterpart to VerifySlackSignature.
 *
 * Dropbox's own one-time GET verification handshake (?challenge=...), sent when the webhook URL
 * is first registered, carries no signature at all — it's just Dropbox confirming the endpoint
 * exists, answered directly by Dropbox\EventsController. Only the POST notifications this
 * middleware actually guards are signed.
 */
class VerifyDropboxSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('get')) {
            return $next($request);
        }

        // The app secret doubles as the OAuth client_secret and the webhook signing key —
        // Dropbox uses the one app secret for both roles.
        $appSecret = config('services.dropbox.client_secret');

        if (! is_string($appSecret) || blank($appSecret)) {
            abort(500, 'Dropbox app secret is not configured.');
        }

        $signature = $request->header('X-Dropbox-Signature');

        if (blank($signature)) {
            abort(401, 'Missing Dropbox signature header.');
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expectedSignature, $signature)) {
            abort(401, 'Invalid Dropbox signature.');
        }

        return $next($request);
    }
}
