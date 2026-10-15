<?php

namespace App\Http\Middleware;

use App\Models\ReferralVisit;
use Closure;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a guest's arrival at /login or /register via a marketing link carrying ?ref=<code>,
 * then redirects to the same URL without ?ref so a refresh or a shared copy of the link isn't
 * counted again. Logged-in users, bots (including link-preview fetchers like Slackbot) and
 * sessions that already recorded a visit are redirected without recording.
 */
class RecordReferralVisit
{
    private const ROUTES = ['login', 'register'];

    private const MAX_REF_LENGTH = 255;

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->has('ref') || ! $request->routeIs(self::ROUTES)) {
            return $next($request);
        }

        $ref = $request->query('ref');

        if (is_string($ref) && $this->shouldRecord($request)) {
            $this->record($request, mb_substr(trim($ref), 0, self::MAX_REF_LENGTH));
        }

        return redirect()->to($request->fullUrlWithoutQuery('ref'));
    }

    private function shouldRecord(Request $request): bool
    {
        if (trim((string) $request->query('ref')) === '' || $request->user() !== null) {
            return false;
        }

        return ! $request->session()->has(ReferralVisit::SESSION_KEY);
    }

    private function record(Request $request, string $ref): void
    {
        $userAgent = (string) $request->userAgent();

        if ($userAgent === '') {
            return;
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        // HTTP libraries (curl, python-requests, Go-http-client) aren't flagged as bots by the
        // detector, but they're scripts, not people.
        if ($detector->isBot() || $detector->getClient('type') === 'library') {
            return;
        }

        $browser = $detector->getClient('name');
        $deviceType = $detector->getDeviceName();

        $visit = ReferralVisit::create([
            'ref' => $ref,
            'landing_path' => '/'.ltrim($request->path(), '/'),
            'country' => $this->country($request),
            'device_type' => $deviceType !== '' ? $deviceType : null,
            'browser' => is_string($browser) && $browser !== '' && $browser !== DeviceDetector::UNKNOWN ? $browser : null,
            'referrer_host' => $this->referrerHost($request),
        ]);

        $request->session()->put(ReferralVisit::SESSION_KEY, $visit->id);
    }

    /**
     * Cloudflare's geolocation of the visitor, used in place of storing their IP. Cloudflare
     * sends XX for unknown and T1 for Tor, both kept as-is.
     */
    private function country(Request $request): ?string
    {
        $country = strtoupper(trim((string) $request->header('CF-IPCountry')));

        return preg_match('/^[A-Z0-9]{2}$/', $country) === 1 ? $country : null;
    }

    /**
     * Host only — a full referrer URL can carry emails or tokens in its path or query.
     */
    private function referrerHost(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_substr(strtolower($host), 0, 255) : null;
    }
}
