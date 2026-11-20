<?php

namespace App\Services\Demo;

use App\Models\DemoSession;
use App\Models\ReferralVisit;
use App\Services\VisitorContext;
use Illuminate\Http\Request;

/**
 * Records each step of a guest's journey through the public demo on their DemoSession row:
 * landing on /demo, starting a sandbox or being turned away, leaving for sign-up, and
 * registering (see MarkDemoSessionRegistered).
 */
class DemoTracker
{
    private const MAX_PARAM_LENGTH = 255;

    private const TRACKED_PARAMS = ['ref', 'utm_source', 'utm_medium', 'utm_campaign'];

    public function __construct(private readonly VisitorContext $visitorContext) {}

    /**
     * Recorded once per browser session, for guests only — signed-in users and bots aren't
     * prospects.
     */
    public function landed(Request $request): void
    {
        if ($request->user() !== null || DemoSession::current() !== null) {
            return;
        }

        $visitor = $this->visitorContext->forHuman($request);

        if ($visitor === null) {
            return;
        }

        $referralVisitId = $request->session()->get(ReferralVisit::SESSION_KEY);

        $demoSession = DemoSession::create([
            ...$this->trackedParams($request),
            ...$visitor,
            'referral_visit_id' => is_int($referralVisitId) ? $referralVisitId : null,
        ]);

        $request->session()->put(DemoSession::SESSION_KEY, $demoSession->id);
    }

    public function started(): void
    {
        DemoSession::current()?->update(['started_at' => now()]);
    }

    public function rejected(string $reason): void
    {
        DemoSession::current()?->update(['rejected_at' => now(), 'rejected_reason' => $reason]);
    }

    public function exited(): void
    {
        DemoSession::current()?->update(['exited_at' => now()]);
    }

    /**
     * @return array<string, string|null>
     */
    private function trackedParams(Request $request): array
    {
        $params = [];

        foreach (self::TRACKED_PARAMS as $name) {
            $value = $request->query($name);
            $value = is_string($value) ? trim($value) : '';
            $params[$name] = $value !== '' ? mb_substr($value, 0, self::MAX_PARAM_LENGTH) : null;
        }

        return $params;
    }
}
