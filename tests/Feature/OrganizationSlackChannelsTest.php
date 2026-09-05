<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SlackChannelBinding;
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

    $this->workspace = SlackWorkspace::factory()->create(['organization_id' => $this->org->id]);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);
});

// ── Organization dashboard channel data ─────────────────────────────────────

it('lists existing bindings and unbound available channels on the organization dashboard', function () {
    SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $this->workspace->id,
        'channel_id' => 'C1',
        'channel_name' => 'general',
        'project_id' => $this->project->id,
    ]);

    Http::fake([
        'slack.com/api/conversations.list*' => Http::response([
            'ok' => true,
            'channels' => [
                ['id' => 'C1', 'name' => 'general'],
                ['id' => 'C2', 'name' => 'random'],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertInertia(fn ($page) => $page
            ->has('slackBindings', 1)
            ->where('slackBindings.0.channel_name', 'general')
            ->where('slackBindings.0.project.name', 'Test Project')
            ->has('slackAvailableChannels', 1)
            ->where('slackAvailableChannels.0.id', 'C2')
        );
});

it('degrades to an empty available-channel list, keeping bindings, when the slack api call fails', function () {
    SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $this->workspace->id,
        'channel_id' => 'C1',
        'channel_name' => 'general',
        'project_id' => $this->project->id,
    ]);

    Http::fake([
        'slack.com/api/conversations.list*' => Http::response(['ok' => false, 'error' => 'invalid_auth'], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('slackBindings', 1)
            ->has('slackAvailableChannels', 0)
        );
});

// ── Store ───────────────────────────────────────────────────────────────────

it('creates a new binding', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.slack.channels.store', $this->org), [
            'channel_id' => 'C1',
            'channel_name' => 'general',
            'project_id' => $this->project->id,
        ])
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id]));

    $binding = SlackChannelBinding::where('slack_workspace_id', $this->workspace->id)->where('channel_id', 'C1')->first();

    expect($binding)->not->toBeNull()
        ->and($binding->channel_name)->toBe('general')
        ->and($binding->project_id)->toBe($this->project->id);
});

it('repoints an existing binding to a different project instead of erroring', function () {
    $otherProject = Project::create(['name' => 'Other Project', 'client_id' => $this->client->id]);

    SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $this->workspace->id,
        'channel_id' => 'C1',
        'channel_name' => 'general',
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('organizations.slack.channels.store', $this->org), [
            'channel_id' => 'C1',
            'channel_name' => 'general',
            'project_id' => $otherProject->id,
        ])
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id]));

    expect(SlackChannelBinding::where('slack_workspace_id', $this->workspace->id)->where('channel_id', 'C1')->count())->toBe(1)
        ->and(SlackChannelBinding::where('channel_id', 'C1')->first()->project_id)->toBe($otherProject->id);
});

it('rejects binding to a project outside the organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $outsideProject = Project::create(['name' => 'Outside Project', 'client_id' => $otherClient->id]);

    $this->actingAs($this->user)
        ->post(route('organizations.slack.channels.store', $this->org), [
            'channel_id' => 'C1',
            'channel_name' => 'general',
            'project_id' => $outsideProject->id,
        ])
        ->assertNotFound();

    expect(SlackChannelBinding::where('channel_id', 'C1')->exists())->toBeFalse();
});

it('forbids a non-admin org member from creating a binding', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->post(route('organizations.slack.channels.store', $this->org), [
            'channel_id' => 'C1',
            'channel_name' => 'general',
            'project_id' => $this->project->id,
        ])
        ->assertNotFound();

    expect(SlackChannelBinding::where('channel_id', 'C1')->exists())->toBeFalse();
});

// ── Destroy ─────────────────────────────────────────────────────────────────

it('deletes a binding', function () {
    $binding = SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $this->workspace->id,
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('organizations.slack.channels.destroy', [$this->org, $binding]))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id]));

    expect(SlackChannelBinding::find($binding->id))->toBeNull();
});

it('404s deleting a binding that belongs to a different organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherWorkspace = SlackWorkspace::factory()->create(['organization_id' => $otherOrg->id]);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create(['name' => 'Other Project', 'client_id' => $otherClient->id]);

    $binding = SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $otherWorkspace->id,
        'project_id' => $otherProject->id,
    ]);

    // This user is only an admin of $this->org, not $otherOrg — but the org used to
    // authorize this request is the one in the route ($this->org), and the binding
    // belongs to $otherWorkspace/$otherOrg, so the mismatch is caught even though the
    // Gate check on $this->org alone would pass.
    $this->actingAs($this->user)
        ->delete(route('organizations.slack.channels.destroy', [$this->org, $binding]))
        ->assertNotFound();

    expect(SlackChannelBinding::find($binding->id))->not->toBeNull();
});
