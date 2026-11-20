<?php

namespace App\Http\Middleware;

use App\Models\ReferralVisit;
use App\Services\VisitorContext;
use Closure;
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

    public function __construct(private readonly VisitorContext $visitorContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
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
        $visitor = $this->visitorContext->forHuman($request);

        if ($visitor === null) {
            return;
        }

        $visit = ReferralVisit::create([
            'ref' => $ref,
            'landing_path' => '/'.ltrim($request->path(), '/'),
            ...$visitor,
        ]);

        $request->session()->put(ReferralVisit::SESSION_KEY, $visit->id);
    }
}
