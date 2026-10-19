<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class MembershipGuard
{
    /**
     * Return an upgrade-required response, or null if the limit is not reached.
     */
    public static function check(Organization $org, string $limitKey): RedirectResponse|JsonResponse|null
    {
        $atLimits = $org->atLimits();
        $key = match ($limitKey) {
            'users' => 'users',
            'clients' => 'clients',
            'projects' => 'projects',
            'ai_docs' => 'ai_docs',
            default => null,
        };

        if (! $key || ! ($atLimits[$key] ?? false)) {
            return null;
        }

        if ($org->membership_tier === Organization::TIER_DEMO) {
            $message = $key === 'ai_docs'
                ? "You've used all the AI runs included in the demo. Start a free trial to keep going."
                : "You've reached the demo's limit. Start a free trial to keep going.";

            return request()->expectsJson() && ! request()->header('X-Inertia')
                ? response()->json(['message' => $message], 403)
                : back()->with('error', $message);
        }

        if (request()->expectsJson()) {
            return response()->json([
                'upgrade_required' => true,
                'tier' => $org->membership_tier,
                'limit_key' => $limitKey,
            ], 403);
        }

        return back()->with('upgrade_required', $limitKey);
    }
}
