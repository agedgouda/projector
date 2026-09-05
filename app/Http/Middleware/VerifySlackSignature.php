<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Slack's request signature (https://api.slack.com/authentication/verifying-requests-from-slack)
 * on every inbound Slack webhook (Events API, slash commands, interactivity) before anything else
 * touches the payload.
 */
class VerifySlackSignature
{
    /**
     * Requests older than this are rejected even with a valid signature, to block replay of a
     * captured request — the same 5-minute window Slack's own docs specify.
     */
    private const MAX_REQUEST_AGE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $signingSecret = config('services.slack.signing_secret');

        if (! is_string($signingSecret) || blank($signingSecret)) {
            abort(500, 'Slack signing secret is not configured.');
        }

        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (blank($timestamp) || blank($signature)) {
            abort(401, 'Missing Slack signature headers.');
        }

        if (abs(time() - (int) $timestamp) > self::MAX_REQUEST_AGE_SECONDS) {
            abort(401, 'Slack request timestamp is too old.');
        }

        $baseString = 'v0:'.$timestamp.':'.$request->getContent();
        $expectedSignature = 'v0='.hash_hmac('sha256', $baseString, $signingSecret);

        if (! hash_equals($expectedSignature, $signature)) {
            abort(401, 'Invalid Slack signature.');
        }

        return $next($request);
    }
}
