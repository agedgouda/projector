<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GoogleOauthToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;

class IntegrationsController extends Controller
{
    private const GOOGLE_SCOPES = ['https://www.googleapis.com/auth/drive.file'];

    /**
     * Show the user's integrations settings page.
     */
    public function edit(Request $request): Response
    {
        $token = $request->user()->googleOauthToken;

        return Inertia::render('settings/Integrations', [
            'googleConnected' => (bool) $token,
            'googleEmail' => $token?->google_email,
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
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
}
