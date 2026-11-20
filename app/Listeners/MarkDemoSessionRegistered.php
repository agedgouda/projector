<?php

namespace App\Listeners;

use App\Models\DemoSession;
use Illuminate\Auth\Events\Registered;

/**
 * Flags this browser session's demo session (if any) as registered when the guest signs up.
 * Only a timestamp is set — the demo session is never linked to the new user, keeping it free
 * of personal data.
 */
class MarkDemoSessionRegistered
{
    public function handle(Registered $event): void
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return;
        }

        $demoSessionId = request()->session()->pull(DemoSession::SESSION_KEY);

        if ($demoSessionId === null) {
            return;
        }

        DemoSession::query()
            ->whereKey($demoSessionId)
            ->whereNull('registered_at')
            ->update(['registered_at' => now()]);
    }
}
