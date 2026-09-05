<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\SlackWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OrganizationSlackController extends Controller
{
    /**
     * Bot scopes requested for the whole Slack feature set (task/event creation, file import) up
     * front, since Slack requires a fresh install/re-authorization any time the requested scopes
     * change — not just whatever the currently-built slice of the feature needs.
     */
    private const SLACK_BOT_SCOPES = ['chat:write', 'commands', 'files:read', 'channels:history', 'channels:read', 'groups:read', 'users:read'];

    /**
     * Redirect to Slack's "Add to Slack" consent screen to install the bot into this
     * organization's workspace. Unlike Google (a per-user connection via Socialite), this is a
     * per-organization install done with raw HTTP calls against Slack's OAuth v2 endpoints —
     * there's no Socialite driver for it, and matching the existing SlackMeetingDriver's own
     * direct-HTTP style keeps every Slack API call in this codebase looking the same.
     *
     * The callback URL registered with Slack has to be a single, fixed, pre-registered string —
     * Slack rejects any redirect_uri that isn't an exact match against the app's own configured
     * list, so it can't carry a per-organization {organization} segment the way this route does.
     * Which organization the flow is for is instead carried in the session (see callback()
     * below), the same way connect()/callback() pair up for any CSRF-style `state` round trip.
     */
    public function connect(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        if (blank(config('services.slack.client_id')) || blank(config('services.slack.client_secret')) || blank(config('services.slack.signing_secret'))) {
            return $this->redirectToOrganization($organization)->with('status', 'slack-not-configured');
        }

        $state = Str::random(40);
        $request->session()->put('slack_connect_state', $state);
        $request->session()->put('slack_connect_organization_id', $organization->id);

        $query = http_build_query([
            'client_id' => config('services.slack.client_id'),
            'scope' => implode(',', self::SLACK_BOT_SCOPES),
            'redirect_uri' => route('organizations.slack.callback'),
            'state' => $state,
        ]);

        return redirect()->away("https://slack.com/oauth/v2/authorize?{$query}");
    }

    /**
     * Handle Slack's OAuth callback, exchanging the code for a bot token and storing it against
     * the organization that started the install (captured in the session by connect(), since
     * Slack's redirect — a fixed URL shared by every organization — carries no organization
     * identifier of its own).
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('slack_connect_state');
        $organizationId = $request->session()->pull('slack_connect_organization_id');

        if (! is_string($organizationId) || blank($organizationId) || blank($state) || $request->query('state') !== $state) {
            return to_route('dashboard')->with('status', 'slack-connect-failed');
        }

        $organization = Organization::find($organizationId);

        if ($organization === null) {
            return to_route('dashboard')->with('status', 'slack-connect-failed');
        }

        Gate::authorize('update', $organization);

        if (blank($request->query('code'))) {
            return $this->redirectToOrganization($organization)->with('status', 'slack-connect-failed');
        }

        $response = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
            'client_id' => config('services.slack.client_id'),
            'client_secret' => config('services.slack.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => route('organizations.slack.callback'),
        ]);

        if ($response->failed() || ! $response->json('ok')) {
            return $this->redirectToOrganization($organization)->with('status', 'slack-connect-failed');
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        SlackWorkspace::updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'team_id' => $response->json('team.id'),
                'team_name' => $response->json('team.name'),
                'bot_access_token' => $response->json('access_token'),
                'bot_user_id' => $response->json('bot_user_id'),
                'scopes' => $response->json('scope'),
                'installed_by_user_id' => $user->id,
            ]
        );

        return $this->redirectToOrganization($organization)->with('status', 'slack-connected');
    }

    /**
     * Disconnect the organization's Slack workspace.
     */
    public function disconnect(Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $organization->slackWorkspace?->delete();

        return $this->redirectToOrganization($organization)->with('status', 'slack-disconnected');
    }

    /**
     * Slack settings live on the organization's own dashboard (the Configuration tab of
     * Organizations/Show.vue, reached via organizations.index), not a separate edit page —
     * matching how every other connect/disconnect flow in this controller ends up back where the
     * user actually manages the org, with `org` selecting it the same way Projects/Index and the
     * org switcher already do.
     */
    private function redirectToOrganization(Organization $organization): RedirectResponse
    {
        return to_route('organizations.index', ['org' => $organization->id]);
    }
}
