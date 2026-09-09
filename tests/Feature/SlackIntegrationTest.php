<?php

use App\Models\Organization;
use App\Models\SlackWorkspace;
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
        'services.slack.client_id' => 'fake-client-id',
        'services.slack.client_secret' => 'fake-client-secret',
        'services.slack.signing_secret' => 'fake-signing-secret',
    ]);
});

// ── Organization dashboard (Configuration tab) ─────────────────────────────

it('reports slack as not configured when app credentials are missing', function () {
    config(['services.slack.client_id' => null]);

    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertInertia(fn ($page) => $page->where('slackConfigured', false));
});

it('reports slack as configured and not connected by default', function () {
    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertInertia(fn ($page) => $page
            ->where('slackConfigured', true)
            ->where('slackConnected', false)
        );
});

it('reports the connected workspace name', function () {
    SlackWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'team_name' => 'Acme Corp',
    ]);

    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertInertia(fn ($page) => $page
            ->where('slackConnected', true)
            ->where('slackTeamName', 'Acme Corp')
        );
});

// ── Connect redirect ────────────────────────────────────────────────────────

it('redirects to slack with the org-scoped bot scopes and a fixed callback url', function () {
    $response = $this->actingAs($this->user)
        ->get(route('organizations.slack.connect', $this->org));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('slack.com/oauth/v2/authorize')
        ->and($location)->toContain('client_id=fake-client-id')
        ->and(urldecode($location))->toContain('scope=chat:write,commands,files:read,channels:history,channels:read,groups:read,users:read')
        ->and(urldecode($location))->toContain('redirect_uri='.route('organizations.slack.callback'));

    expect(session('slack_connect_state'))->not->toBeNull()
        ->and(session('slack_connect_organization_id'))->toBe($this->org->id);
});

it('redirects back with a status instead of a broken slack url when app credentials are missing', function () {
    config(['services.slack.client_id' => null]);

    $response = $this->actingAs($this->user)
        ->get(route('organizations.slack.connect', $this->org));

    $response->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));
    expect(session('status'))->toBe('slack-not-configured');
});

it('404s a non-admin org member starting the slack connect flow', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->get(route('organizations.slack.connect', $this->org))
        ->assertNotFound();
});

// ── Callback ────────────────────────────────────────────────────────────────

it('stores the workspace when the slack callback succeeds', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-fake-token',
            'bot_user_id' => 'U123',
            'scope' => 'chat:write,commands',
            'team' => ['id' => 'T123', 'name' => 'Acme Corp'],
        ], 200),
    ]);

    $this->withSession([
        'slack_connect_state' => 'abc123',
        'slack_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    $workspace = SlackWorkspace::where('organization_id', $this->org->id)->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->team_id)->toBe('T123')
        ->and($workspace->team_name)->toBe('Acme Corp')
        ->and($workspace->bot_access_token)->toBe('xoxb-fake-token')
        ->and($workspace->bot_user_id)->toBe('U123')
        ->and($workspace->installed_by_user_id)->toBe($this->user->id);

    expect(session('status'))->toBe('slack-connected');
});

it('rejects a callback with a mismatched state', function () {
    $this->withSession([
        'slack_connect_state' => 'abc123',
        'slack_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.slack.callback', ['code' => 'fake-code', 'state' => 'wrong']))
        ->assertRedirect(route('dashboard'));

    expect(session('status'))->toBe('slack-connect-failed')
        ->and(SlackWorkspace::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('does not store a workspace when slack reports a failed exchange', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(['ok' => false, 'error' => 'invalid_code'], 200),
    ]);

    $this->withSession([
        'slack_connect_state' => 'abc123',
        'slack_connect_organization_id' => $this->org->id,
    ])
        ->actingAs($this->user)
        ->get(route('organizations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    expect(session('status'))->toBe('slack-connect-failed')
        ->and(SlackWorkspace::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

// ── Multiple organizations, same real Slack workspace ──────────────────────

it('lets a second organization connect the same slack team as an existing organization', function () {
    SlackWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'team_id' => 'T123',
    ]);

    $secondOrg = Organization::create(['name' => 'Second Org']);
    $secondUser = User::factory()->create();
    $secondOrg->users()->attach($secondUser->id, ['role' => 'org-admin']);

    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-second-token',
            'bot_user_id' => 'U123',
            'scope' => 'chat:write,commands',
            'team' => ['id' => 'T123', 'name' => 'Acme Corp'],
        ], 200),
    ]);

    $this->withSession([
        'slack_connect_state' => 'abc123',
        'slack_connect_organization_id' => $secondOrg->id,
    ])
        ->actingAs($secondUser)
        ->get(route('organizations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('organizations.index', ['org' => $secondOrg->id, 'tab' => 'configuration']));

    expect(SlackWorkspace::where('team_id', 'T123')->count())->toBe(2)
        ->and(SlackWorkspace::where('organization_id', $secondOrg->id)->first()->bot_access_token)->toBe('xoxb-second-token')
        ->and(SlackWorkspace::where('organization_id', $this->org->id)->exists())->toBeTrue();

    expect(session('status'))->toBe('slack-connected');
});

// ── Disconnect ──────────────────────────────────────────────────────────────

it('deletes the workspace on disconnect', function () {
    SlackWorkspace::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->user)
        ->delete(route('organizations.slack.disconnect', $this->org))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    expect(SlackWorkspace::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('404s a non-admin org member disconnecting slack', function () {
    SlackWorkspace::factory()->create(['organization_id' => $this->org->id]);

    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->delete(route('organizations.slack.disconnect', $this->org))
        ->assertNotFound();

    expect(SlackWorkspace::where('organization_id', $this->org->id)->exists())->toBeTrue();
});
