<?php

namespace App\Http\Middleware;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs what a demo visitor does inside their sandbox — every page they visit and every action
 * they take — as DemoEvent rows on their demo session, by route name only. Background requests
 * (polling, status checks) are skipped so the log reflects what the visitor actually did.
 */
class RecordDemoActivity
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $routeName = $request->route()?->getName();

        if (! $user instanceof User || ! $user->isDemo() || ! is_string($routeName) || ! $this->isVisitorAction($request)) {
            return $response;
        }

        $demoSessionId = $request->session()->get(DemoSession::SESSION_KEY);

        if (is_int($demoSessionId)) {
            DemoEvent::create([
                'demo_session_id' => $demoSessionId,
                'event' => $routeName,
                'method' => $request->method(),
            ]);
        }

        return $response;
    }

    /**
     * A page visit (full load or Inertia navigation) or any state-changing request. Plain
     * GET XHR/JSON requests and Inertia partial reloads (polling) are the app's own background
     * fetches.
     */
    private function isVisitorAction(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return true;
        }

        if ($request->hasHeader('X-Inertia-Partial-Data')) {
            return false;
        }

        return $request->hasHeader('X-Inertia') || ! $request->expectsJson();
    }
}
