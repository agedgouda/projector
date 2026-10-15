<?php

namespace App\Listeners;

use App\Models\ReferralVisit;
use Illuminate\Auth\Events\Registered;

/**
 * Flags this session's referral visit (if any) as converted when the guest registers. Only a
 * timestamp is set — the visit is never linked to the new user, keeping it free of personal data.
 */
class MarkReferralVisitConverted
{
    public function handle(Registered $event): void
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return;
        }

        $visitId = request()->session()->pull(ReferralVisit::SESSION_KEY);

        if ($visitId === null) {
            return;
        }

        ReferralVisit::query()
            ->whereKey($visitId)
            ->whereNull('converted_at')
            ->update(['converted_at' => now()]);
    }
}
