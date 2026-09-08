<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GoogleOauthToken;
use App\Models\SlackUserIdentity;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;

class IntegrationsController extends Controller
{
    private const GOOGLE_SCOPES = ['https://www.googleapis.com/auth/drive.file'];

    /**
     * Slack user-token scopes for identity linking — deliberately no bot `scope` requested here
     * (see connectSlack()), so this never touches an organization's bot install.
     */
    private const SLACK_USER_SCOPES = ['identity.basic', 'identity.team'];

    /**
     * Show the user's integrations settings page. The org-level Slack bot connection is managed
     * from Organization settings instead (see OrganizationSlackController) — what lives here is
     * only the per-user Slack identity link(s) used to attribute Slack-triggered actions.
     */
    public function edit(Request $request): Response
    {
        $token = $request->user()->googleOauthToken;

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Integrations', [
            'googleConnected' => (bool) $token,
            'googleEmail' => $token?->google_email,
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            'slackConfigured' => filled(config('services.slack.client_id')) && filled(config('services.slack.client_secret')) && filled(config('services.slack.signing_secret')),
            'slackIdentities' => $user->slackIdentities()->get(['id', 'slack_team_id', 'slack_username'])->map(fn (SlackUserIdentity $identity) => [
                'id' => $identity->id,
                'slack_username' => $identity->slack_username,
                // A workspace can be disconnected after an identity link is made — fall back to
                // the raw team ID rather than losing the row's display name entirely.
                'team_name' => SlackWorkspace::where('team_id', $identity->slack_team_id)->value('team_name') ?? $identity->slack_team_id,
            ]),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Redirect the user to Google's consent screen. Callers elsewhere in the app (e.g. the
     * Google Sheets/Docs export buttons) can pass `return_to` so the callback sends the user
     * back to what they were doing instead of always landing on this settings page.
     * Restricted to same-origin relative paths (never a bare "//host" either) since it's an
     * unvalidated query param and the callback redirects to it directly.
     */
    public function connectGoogle(Request $request): RedirectResponse
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            return to_route('integrations.edit')->with('status', 'google-not-configured');
        }

        $returnTo = $request->query('return_to');

        if (is_string($returnTo) && str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
            $request->session()->put('google_connect_return_to', $returnTo);
        } else {
            $request->session()->forget('google_connect_return_to');
        }

        return Socialite::driver('google')
            ->scopes(self::GOOGLE_SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    /**
     * Handle Google's OAuth callback, storing the user's tokens.
     */
    public function googleCallback(Request $request): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();
        $returnTo = $request->session()->pull('google_connect_return_to');

        if (! $googleUser->refreshToken) {
            return to_route('integrations.edit')->with('status', 'google-connect-failed');
        }

        // Google doesn't always grant everything a request asks for (approvedScopes may be a
        // subset of what was requested — e.g. an org's Workspace admin restricting third-party
        // app scopes, or Google being stricter with an app still in Testing publishing status).
        // Storing the connection anyway, unchecked, previously meant the missing scope only
        // surfaced later as a confusing 500 the first time an export tried to use it — this
        // catches it immediately, at connect time, instead.
        if (array_diff(self::GOOGLE_SCOPES, $googleUser->approvedScopes ?? [])) {
            return to_route('integrations.edit')->with('status', 'google-scope-missing');
        }

        GoogleOauthToken::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'google_email' => $googleUser->email,
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken,
                'expires_at' => now()->addSeconds((int) ($googleUser->expiresIn ?? 3600)),
                'scopes' => implode(' ', $googleUser->approvedScopes),
            ]
        );

        if (is_string($returnTo)) {
            return redirect()->to($returnTo)->with('status', 'google-connected');
        }

        return to_route('integrations.edit')->with('status', 'google-connected');
    }

    /**
     * Disconnect the user's Google account.
     */
    public function disconnectGoogle(Request $request): RedirectResponse
    {
        $request->user()->googleOauthToken?->delete();

        return to_route('integrations.edit')->with('status', 'google-disconnected');
    }

    /**
     * Redirect to Slack's "Sign in with Slack" consent screen to link the current user's Slack
     * identity. Unlike OrganizationSlackController::connect() (which installs the bot into a
     * workspace and requires org-admin), this requests only `user_scope` and no bot `scope` at
     * all — any authenticated user can link their own identity, in any Slack workspace, without
     * touching that workspace's bot install. Which organization this identity is useful for is
     * resolved later, at callback time, by matching the returned team ID against an already
     * connected SlackWorkspace.
     */
    public function connectSlack(Request $request): RedirectResponse
    {
        if (blank(config('services.slack.client_id')) || blank(config('services.slack.client_secret')) || blank(config('services.slack.signing_secret'))) {
            return to_route('integrations.edit')->with('status', 'slack-not-configured');
        }

        $state = Str::random(40);
        $request->session()->put('slack_identity_state', $state);

        $query = http_build_query([
            'client_id' => config('services.slack.client_id'),
            'user_scope' => implode(',', self::SLACK_USER_SCOPES),
            'redirect_uri' => route('integrations.slack.callback'),
            'state' => $state,
        ]);

        return redirect()->away("https://slack.com/oauth/v2/authorize?{$query}");
    }

    /**
     * Handle Slack's identity-link callback. Only links the identity if the returned Slack team
     * matches a workspace some organization has already connected, and the current user actually
     * belongs to that organization — otherwise this would let anyone link an identity against a
     * workspace they have no relationship to in Projector.
     */
    public function slackCallback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('slack_identity_state');

        if (blank($state) || $request->query('state') !== $state || blank($request->query('code'))) {
            return to_route('integrations.edit')->with('status', 'slack-connect-failed');
        }

        $response = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
            'client_id' => config('services.slack.client_id'),
            'client_secret' => config('services.slack.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => route('integrations.slack.callback'),
        ]);

        $authedUserToken = $response->json('authed_user.access_token');
        $teamId = $response->json('team.id');

        if ($response->failed() || ! $response->json('ok') || ! is_string($authedUserToken) || blank($authedUserToken) || blank($teamId)) {
            return to_route('integrations.edit')->with('status', 'slack-connect-failed');
        }

        /** @var User $user */
        $user = $request->user();

        // More than one organization can have this same Slack team connected (e.g. an agency's
        // client orgs all living in one Slack workspace) — checking membership against every
        // organization_id tied to this team_id, not just one arbitrarily-picked workspace row,
        // is what makes the identity link succeed for a user who belongs to any of them.
        $connectedOrganizationIds = SlackWorkspace::where('team_id', $teamId)->pluck('organization_id');

        if ($connectedOrganizationIds->isEmpty() || ! $user->organizations()->whereIn('organizations.id', $connectedOrganizationIds)->exists()) {
            return to_route('integrations.edit')->with('status', 'slack-team-not-connected');
        }

        $identityResponse = Http::withToken($authedUserToken)->get('https://slack.com/api/users.identity');
        $slackUserId = $identityResponse->json('user.id');

        if ($identityResponse->failed() || ! $identityResponse->json('ok') || blank($slackUserId)) {
            return to_route('integrations.edit')->with('status', 'slack-connect-failed');
        }

        try {
            SlackUserIdentity::updateOrCreate(
                ['user_id' => $user->id, 'slack_team_id' => $teamId],
                ['slack_user_id' => $slackUserId, 'slack_username' => $identityResponse->json('user.name')]
            );
        } catch (QueryException) {
            // The (slack_team_id, slack_user_id) unique constraint rejected this — that exact
            // Slack identity is already linked to a different Projector user.
            return to_route('integrations.edit')->with('status', 'slack-identity-taken');
        }

        return to_route('integrations.edit')->with('status', 'slack-connected');
    }

    /**
     * Remove one of the current user's linked Slack identities.
     */
    public function disconnectSlack(Request $request, SlackUserIdentity $identity): RedirectResponse
    {
        abort_unless($identity->user_id === $request->user()->id, 404);

        $identity->delete();

        return to_route('integrations.edit')->with('status', 'slack-disconnected');
    }
}
