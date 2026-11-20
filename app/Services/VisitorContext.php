<?php

namespace App\Services;

use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;

/**
 * The anonymous facts about a visitor that marketing tracking stores (referral visits, demo
 * sessions). Holds nothing that counts as personal data: no IP, no raw user agent and no full
 * referrer URL.
 */
class VisitorContext
{
    /**
     * Null for bots, link-preview fetchers, HTTP libraries and requests with no user agent —
     * none of which should be counted as a visit.
     *
     * @return array{country: string|null, device_type: string|null, browser: string|null, referrer_host: string|null}|null
     */
    public function forHuman(Request $request): ?array
    {
        $userAgent = (string) $request->userAgent();

        if ($userAgent === '') {
            return null;
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        if ($detector->isBot() || $detector->getClient('type') === 'library') {
            return null;
        }

        $browser = $detector->getClient('name');
        $deviceType = $detector->getDeviceName();

        return [
            'country' => $this->country($request),
            'device_type' => $deviceType !== '' ? $deviceType : null,
            'browser' => is_string($browser) && $browser !== '' && $browser !== DeviceDetector::UNKNOWN ? $browser : null,
            'referrer_host' => $this->referrerHost($request),
        ];
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
