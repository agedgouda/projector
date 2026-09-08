<?php

use App\Models\Organization;
use App\Models\SlackUserIdentity;
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

    $this->workspace = SlackWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'team_id' => 'T123',
        'team_name' => 'Acme Corp',
    ]);

    config([
        'services.slack.client_id' => 'fake-client-id',
        'services.slack.client_secret' => 'fake-client-secret',
        'services.slack.signing_secret' => 'fake-signing-secret',
    ]);
});

// ── Edit page ────────────────────────────────────────────────────────────────

it('reports slack as not configured when app credentials are missing', function () {
    config(['services.slack.client_id' => null]);

    $this->actingAs($this->user)
        ->get(route('integrations.edit'))
        ->assertInertia(fn ($page) => $page->where('slackConfigured', false));
});

it('lists linked identities with the workspace team name resolved', function () {
    SlackUserIdentity::factory()->create([
        'user_id' => $this->user->id,
        'slack_team_id' => 'T123',
        'slack_username' => 'jeff',
    ]);

    $this->actingAs($this->user)
        ->get(route('integrations.edit'))
        ->assertInertia(fn ($page) => $page
            ->has('slackIdentities', 1)
            ->where('slackIdentities.0.slack_username', 'jeff')
            ->where('slackIdentities.0.team_name', 'Acme Corp')
        );
});

it('falls back to the raw team id when the workspace is no longer connected', function () {
    SlackUserIdentity::factory()->create([
        'user_id' => $this->user->id,
        'slack_team_id' => 'T999',
        'slack_username' => 'jeff',
    ]);

    $this->actingAs($this->user)
        ->get(route('integrations.edit'))
        ->assertInertia(fn ($page) => $page->where('slackIdentities.0.team_name', 'T999'));
});

// ── Connect redirect ────────────────────────────────────────────────────────

it('redirects to slack with user_scope only, no bot scope', function () {
    $response = $this->actingAs($this->user)->get(route('integrations.slack.connect'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('slack.com/oauth/v2/authorize')
        ->and($location)->toContain('client_id=fake-client-id')
        ->and(urldecode($location))->toContain('user_scope=identity.basic,identity.team')
        ->and($location)->not->toContain('&scope=')
        ->and(urldecode($location))->toContain('redirect_uri='.route('integrations.slack.callback'));

    expect(session('slack_identity_state'))->not->toBeNull();
});

it('redirects back with a status instead of a broken slack url when app credentials are missing', function () {
    config(['services.slack.client_id' => null]);

    $this->actingAs($this->user)
        ->get(route('integrations.slack.connect'))
        ->assertRedirect(route('integrations.edit'));

    expect(session('status'))->toBe('slack-not-configured');
});

// ── Callback ────────────────────────────────────────────────────────────────

function fakeSlackIdentityExchange(string $teamId = 'T123', string $slackUserId = 'U1', string $slackUsername = 'jeff'): void
{
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'authed_user' => ['id' => $slackUserId, 'access_token' => 'xoxp-fake-user-token'],
            'team' => ['id' => $teamId, 'name' => 'Acme Corp'],
        ], 200),
        'slack.com/api/users.identity' => Http::response([
            'ok' => true,
            'user' => ['id' => $slackUserId, 'name' => $slackUsername],
        ], 200),
    ]);
}

it('links the identity when the callback succeeds for a connected workspace', function () {
    fakeSlackIdentityExchange();

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($this->user)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('integrations.edit'));

    $identity = SlackUserIdentity::where('user_id', $this->user->id)->first();

    expect($identity)->not->toBeNull()
        ->and($identity->slack_team_id)->toBe('T123')
        ->and($identity->slack_user_id)->toBe('U1')
        ->and($identity->slack_username)->toBe('jeff');

    expect(session('status'))->toBe('slack-connected');
});

it('rejects a callback with a mismatched state', function () {
    fakeSlackIdentityExchange();

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($this->user)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'wrong']))
        ->assertRedirect(route('integrations.edit'));

    expect(session('status'))->toBe('slack-connect-failed')
        ->and(SlackUserIdentity::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('rejects a callback for a team with no connected workspace', function () {
    fakeSlackIdentityExchange(teamId: 'T999');

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($this->user)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('integrations.edit'));

    expect(session('status'))->toBe('slack-team-not-connected')
        ->and(SlackUserIdentity::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('rejects a callback when the user does not belong to the organization owning that workspace', function () {
    fakeSlackIdentityExchange();

    $outsider = User::factory()->create();

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($outsider)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('integrations.edit'));

    expect(session('status'))->toBe('slack-team-not-connected')
        ->and(SlackUserIdentity::where('user_id', $outsider->id)->exists())->toBeFalse();
});

it('rejects a callback claiming a slack identity already linked to a different user', function () {
    $otherUser = User::factory()->create();
    $this->org->users()->attach($otherUser->id, ['role' => 'org-admin']);

    SlackUserIdentity::factory()->create([
        'user_id' => $otherUser->id,
        'slack_team_id' => 'T123',
        'slack_user_id' => 'U1',
    ]);

    fakeSlackIdentityExchange();

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($this->user)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('integrations.edit'));

    expect(session('status'))->toBe('slack-identity-taken')
        ->and(SlackUserIdentity::where('slack_team_id', 'T123')->where('slack_user_id', 'U1')->first()->user_id)->toBe($otherUser->id);
});

it('repoints the same user\'s existing identity for a team rather than duplicating it', function () {
    SlackUserIdentity::factory()->create([
        'user_id' => $this->user->id,
        'slack_team_id' => 'T123',
        'slack_user_id' => 'U-old',
        'slack_username' => 'old-name',
    ]);

    fakeSlackIdentityExchange(slackUserId: 'U-new', slackUsername: 'new-name');

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($this->user)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']));

    expect(SlackUserIdentity::where('user_id', $this->user->id)->count())->toBe(1)
        ->and(SlackUserIdentity::where('user_id', $this->user->id)->first()->slack_user_id)->toBe('U-new');
});

it('links the identity for a user who belongs only to the second of two organizations sharing this slack team', function () {
    $secondOrg = Organization::create(['name' => 'Second Org']);
    SlackWorkspace::factory()->create([
        'organization_id' => $secondOrg->id,
        'team_id' => 'T123',
    ]);

    $secondOrgOnlyUser = User::factory()->create();
    $secondOrg->users()->attach($secondOrgOnlyUser->id, ['role' => 'org-admin']);

    fakeSlackIdentityExchange();

    $this->withSession(['slack_identity_state' => 'abc123'])
        ->actingAs($secondOrgOnlyUser)
        ->get(route('integrations.slack.callback', ['code' => 'fake-code', 'state' => 'abc123']))
        ->assertRedirect(route('integrations.edit'));

    expect(session('status'))->toBe('slack-connected')
        ->and(SlackUserIdentity::where('user_id', $secondOrgOnlyUser->id)->exists())->toBeTrue();
});

// ── Disconnect ──────────────────────────────────────────────────────────────

it('deletes the identity on disconnect', function () {
    $identity = SlackUserIdentity::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->delete(route('integrations.slack.disconnect', $identity))
        ->assertRedirect(route('integrations.edit'));

    expect(SlackUserIdentity::find($identity->id))->toBeNull();
});

it('404s disconnecting a different user\'s identity', function () {
    $otherUser = User::factory()->create();
    $identity = SlackUserIdentity::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($this->user)
        ->delete(route('integrations.slack.disconnect', $identity))
        ->assertNotFound();

    expect(SlackUserIdentity::find($identity->id))->not->toBeNull();
});
