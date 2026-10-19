<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartDemoRequest;
use App\Models\User;
use App\Services\Demo\DemoSamples;
use App\Services\Demo\DemoSandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public demo: each visitor gets a temporary account with sample projects in the real app
 * (see DemoSandbox), deleted after a day.
 */
class DemoController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDemo()) {
            return $this->redirectIntoSandbox($user);
        }

        return Inertia::render('Demo/Index', [
            'projects' => array_values(array_map(fn (array $sample): array => [
                'name' => $sample['project'],
                'client' => $sample['client'],
                'meeting' => $sample['title'],
            ], DemoSamples::SAMPLES)),
            'lifetimeHours' => DemoSandbox::LIFETIME_HOURS,
            'isSignedIn' => $user instanceof User,
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ]);
    }

    public function start(StartDemoRequest $request, DemoSandbox $sandbox): RedirectResponse
    {
        if (! $this->claimSandbox()) {
            return back()->withErrors(['demo' => 'The demo is busy right now. Please try again tomorrow, or start a free trial.']);
        }

        ['user' => $user] = $sandbox->create();

        if (Auth::check()) {
            Auth::logout();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return $this->redirectIntoSandbox($user);
    }

    /**
     * Leaves the demo for sign-up. The sandbox itself is deleted on schedule.
     */
    public function exit(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDemo()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('register');
    }

    private function redirectIntoSandbox(User $user): RedirectResponse
    {
        $project = $user->favoriteProjects()->orderBy('projects.name')->first();
        $organizationId = $user->organizations()->value('organizations.id');

        if ($project === null || ! is_string($organizationId)) {
            return redirect()->route('dashboard');
        }

        session()->put('active_org_id', $organizationId);
        cookie()->queue(cookie()->forever('last_org_id', $organizationId));

        return redirect()->route('projects.show', ['project' => $project->id, 'tab' => 'hierarchy']);
    }

    /**
     * Counts this sandbox against the site-wide daily cap, which bounds what the demo can cost
     * regardless of how many visitors (or IPs) it gets.
     */
    private function claimSandbox(): bool
    {
        $key = 'demo:sandboxes:'.Carbon::today()->toDateString();
        $limit = config()->integer('services.demo.daily_sandbox_limit', 100);

        Cache::add($key, 0, Carbon::tomorrow()->addHour());

        $createdToday = Cache::get($key, 0);

        if (is_numeric($createdToday) && (int) $createdToday >= $limit) {
            return false;
        }

        Cache::increment($key);

        return true;
    }
}
