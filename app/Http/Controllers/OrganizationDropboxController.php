<?php

namespace App\Http\Controllers;

use App\Models\DropboxWorkspace;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mirrors OrganizationSlackController's org-level "Add to Slack" install almost exactly — the
 * one real structural difference is Dropbox's OAuth also returns a refresh_token (requested via
 * token_access_type=offline), since Dropbox access tokens expire and Slack's bot token doesn't.
 */
class OrganizationDropboxController extends Controller
{
    /**
     * Redirect to Dropbox's OAuth consent screen to connect this organization's Dropbox account.
     *
     * The callback URL registered with the Dropbox app has to be a single, fixed, pre-registered
     * string — same restriction Slack's redirect_uri has — so which organization the flow is for
     * is carried in the session (see callback() below) rather than the URL itself.
     */
    public function connect(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        if (blank(config('services.dropbox.client_id')) || blank(config('services.dropbox.client_secret'))) {
            return $this->redirectToOrganization($organization)->with('status', 'dropbox-not-configured');
        }

        $state = Str::random(40);
        $request->session()->put('dropbox_connect_state', $state);
        $request->session()->put('dropbox_connect_organization_id', $organization->id);

        $query = http_build_query([
            'client_id' => config('services.dropbox.client_id'),
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'redirect_uri' => route('organizations.dropbox.callback'),
            'state' => $state,
        ]);

        return redirect()->away("https://www.dropbox.com/oauth2/authorize?{$query}");
    }

    /**
     * Handle Dropbox's OAuth callback, exchanging the code for an access + refresh token and
     * storing it against the organization that started the connect flow.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('dropbox_connect_state');
        $organizationId = $request->session()->pull('dropbox_connect_organization_id');

        if (! is_string($organizationId) || blank($organizationId) || blank($state) || $request->query('state') !== $state) {
            return to_route('dashboard')->with('status', 'dropbox-connect-failed');
        }

        $organization = Organization::find($organizationId);

        if ($organization === null) {
            return to_route('dashboard')->with('status', 'dropbox-connect-failed');
        }

        Gate::authorize('update', $organization);

        if (blank($request->query('code'))) {
            return $this->redirectToOrganization($organization)->with('status', 'dropbox-connect-failed');
        }

        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'code' => $request->query('code'),
            'grant_type' => 'authorization_code',
            'client_id' => config('services.dropbox.client_id'),
            'client_secret' => config('services.dropbox.client_secret'),
            'redirect_uri' => route('organizations.dropbox.callback'),
        ]);

        $accessToken = $response->json('access_token');

        if ($response->failed() || ! is_string($accessToken)) {
            return $this->redirectToOrganization($organization)->with('status', 'dropbox-connect-failed');
        }

        $accountInfo = Http::withToken($accessToken)
            ->post('https://api.dropboxapi.com/2/users/get_current_account');

        if ($accountInfo->failed()) {
            return $this->redirectToOrganization($organization)->with('status', 'dropbox-connect-failed');
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        $expiresIn = $response->json('expires_in');

        DropboxWorkspace::updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'account_id' => $accountInfo->json('account_id'),
                'account_name' => $accountInfo->json('name.display_name'),
                'access_token' => $accessToken,
                'refresh_token' => $response->json('refresh_token'),
                'access_token_expires_at' => now()->addSeconds(is_int($expiresIn) ? $expiresIn : 14400),
                'installed_by_user_id' => $user->id,
            ]
        );

        return $this->redirectToOrganization($organization)->with('status', 'dropbox-connected');
    }

    /**
     * Disconnect the organization's Dropbox account.
     */
    public function disconnect(Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $organization->dropboxWorkspace?->delete();

        return $this->redirectToOrganization($organization)->with('status', 'dropbox-disconnected');
    }

    /**
     * Dropbox settings live on the organization's own dashboard (the Configuration tab of
     * Organizations/Show.vue), matching the Slack connection's own redirect target.
     */
    private function redirectToOrganization(Organization $organization): RedirectResponse
    {
        return to_route('organizations.index', ['org' => $organization->id]);
    }
}
