<?php

use App\Models\DropboxWorkspace;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    config([
        'services.dropbox.client_id' => 'fake-client-id',
        'services.dropbox.client_secret' => 'fake-client-secret',
    ]);
});

// ── Connect redirect ────────────────────────────────────────────────────────

it('redirects to dropbox with offline access and a fixed callback url', function () {
    $response = $this->actingAs($this->user)
        ->get(route('organizations.dropbox.connect', $this->org));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('www.dropbox.com/oauth2/authorize')
        ->and($location)->toContain('client_id=fake-client-id')
        ->and(urldecode($location))->toContain('token_access_type=offline')
        ->and(urldecode($location))->toContain('redirect_uri='.route('organizations.dropbox.callback'));

    expect(session('dropbox_connect_state'))->not->toBeNull()
        ->and(session('dropbox_connect_organization_id'))->toBe($this->org->id);
});

it('redirects back with a status instead of a broken dropbox url when app credentials are missing', function () {
    config(['services.dropbox.client_id' => null]);

    $response = $this->actingAs($this->user)
        ->get(route('organizations.dropbox.connect', $this->org));

    $response->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));
    expect(session('status'))->toBe('dropbox-not-configured');
});

it('404s a non-admin org member starting the dropbox connect flow', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->get(route('organizations.dropbox.connect', $this->org))
        ->assertNotFound();
});

// ── Callback ────────────────────────────────────────────────────────────────

function fakeDropboxCallbackHttp(): void
{
    Http::fake([
        'api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'sl.fake-access-token',
            'refresh_token' => 'sl.fake-refresh-token',
            'expires_in' => 14400,
            'token_type' => 'bearer',
        ], 200),
        'api.dropboxapi.com/2/users/get_current_account' => Http::response([
            'account_id' => 'dbid:fake-account-id',
            'name' => ['display_name' => 'Acme Corp Dropbox'],
        ], 200),
    ]);
}

it('stores the workspace when the dropbox callback succeeds', function () {
    fakeDropboxCallbackHttp();

    $this->withSession([
        'dropbox_connect_state' => 'abc123',
        'dropbox_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.dropbox.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    $workspace = DropboxWorkspace::where('organization_id', $this->org->id)->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->account_id)->toBe('dbid:fake-account-id')
        ->and($workspace->account_name)->toBe('Acme Corp Dropbox')
        ->and($workspace->access_token)->toBe('sl.fake-access-token')
        ->and($workspace->refresh_token)->toBe('sl.fake-refresh-token')
        ->and($workspace->access_token_expires_at)->not->toBeNull()
        ->and($workspace->installed_by_user_id)->toBe($this->user->id);

    expect(session('status'))->toBe('dropbox-connected');
});

it('sends a literal JSON null, not an empty JSON array or an empty body, when fetching account info', function () {
    // Two real production bugs this guards against regressing: Http::post()'s default $data
    // ([]) json_encodes to the array literal "[]", which Dropbox's zero-argument RPC endpoints
    // reject with a 400; a genuinely empty body isn't valid JSON either, and with
    // Content-Type: application/json still declared, Dropbox threw its own generic 500 trying
    // to parse zero bytes. "null" is Dropbox's actual documented convention for "no argument".
    fakeDropboxCallbackHttp();

    $this->withSession([
        'dropbox_connect_state' => 'abc123',
        'dropbox_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.dropbox.callback', ['code' => 'fake-code', 'state' => 'abc123']));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.dropboxapi.com/2/users/get_current_account'
        && $request->body() === 'null');
});

it('rejects a callback with a mismatched state', function () {
    $this->withSession([
        'dropbox_connect_state' => 'abc123',
        'dropbox_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.dropbox.callback', ['code' => 'fake-code', 'state' => 'wrong']))
        ->assertRedirect(route('dashboard'));

    expect(session('status'))->toBe('dropbox-connect-failed')
        ->and(DropboxWorkspace::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('does not store a workspace when the token exchange fails', function () {
    Http::fake([
        'api.dropboxapi.com/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->withSession([
        'dropbox_connect_state' => 'abc123',
        'dropbox_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.dropbox.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    expect(session('status'))->toBe('dropbox-connect-failed')
        ->and(DropboxWorkspace::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

// ── Disconnect ──────────────────────────────────────────────────────────────

it('deletes the workspace on disconnect', function () {
    DropboxWorkspace::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->user)
        ->delete(route('organizations.dropbox.disconnect', $this->org))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    expect(DropboxWorkspace::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('404s a non-admin org member disconnecting dropbox', function () {
    DropboxWorkspace::factory()->create(['organization_id' => $this->org->id]);

    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->delete(route('organizations.dropbox.disconnect', $this->org))
        ->assertNotFound();

    expect(DropboxWorkspace::where('organization_id', $this->org->id)->exists())->toBeTrue();
});
