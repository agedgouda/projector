<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Svix signature Resend puts on its webhook calls
 * (https://docs.svix.com/receiving/verifying-payloads/how-manual): an HMAC-SHA256 over
 * "{svix-id}.{svix-timestamp}.{raw body}", keyed with the base64-decoded part of the
 * "whsec_..." signing secret, sent as one or more space-separated "v1,{base64}" entries.
 */
class VerifyResendSignature
{
    /**
     * Requests older (or newer) than this are rejected even with a valid signature, to block
     * replay of a captured request.
     */
    private const MAX_REQUEST_AGE_SECONDS = 300;

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.resend.webhook_secret');

        if (! is_string($secret) || blank($secret)) {
            abort(500, 'Resend webhook secret is not configured.');
        }

        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');

        if ($id === '' || $timestamp === '' || $signatures === '') {
            abort(401, 'Missing Resend signature headers.');
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_REQUEST_AGE_SECONDS) {
            abort(401, 'Resend request timestamp is out of range.');
        }

        $key = base64_decode(preg_replace('/^whsec_/', '', $secret) ?? '', true);

        if ($key === false) {
            abort(500, 'Resend webhook secret is malformed.');
        }

        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));

        foreach (explode(' ', $signatures) as $versionedSignature) {
            [$version, $signature] = array_pad(explode(',', $versionedSignature, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        abort(401, 'Invalid Resend signature.');
    }
}
