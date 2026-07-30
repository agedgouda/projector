<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);
});

it('allows org-admin to view project', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);

    $this->actingAs($user)
        ->get(route('projects.show', $this->project))
        ->assertOk();
});

it('allows project-lead to view project', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'project-lead']);

    $this->actingAs($user)
        ->get(route('projects.show', $this->project))
        ->assertOk();
});

it('allows team-member to view project', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);

    $this->actingAs($user)
        ->get(route('projects.show', $this->project))
        ->assertOk();
});

it('denies access to users with no org membership', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.show', $this->project))
        ->assertNotFound();
});

it('denies access to members of a different org', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $user = User::factory()->create();
    $otherOrg->users()->attach($user->id, ['role' => 'team-member']);

    $this->actingAs($user)
        ->get(route('projects.show', $this->project))
        ->assertNotFound();
});

it('allows a member of the project\'s org to view it even when a different org they also belong to is active in the session', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);
    $otherOrg->users()->attach($user->id, ['role' => 'org-admin']);

    $this->actingAs($user)
        ->withSession(['active_org_id' => $otherOrg->id])
        ->get(route('projects.show', $this->project))
        ->assertOk();
});

it('allows a member of the project\'s org to view it even when a different org is active via the last_org_id cookie', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);
    $otherOrg->users()->attach($user->id, ['role' => 'org-admin']);

    $this->actingAs($user)
        ->withCookie('last_org_id', (string) $otherOrg->id)
        ->get(route('projects.show', $this->project))
        ->assertOk();
});

it('switches the active org to the project\'s own org when visited from a different active org', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);
    $otherOrg->users()->attach($user->id, ['role' => 'org-admin']);

    $response = $this->actingAs($user)
        ->withSession(['active_org_id' => $otherOrg->id])
        ->get(route('projects.show', $this->project));

    $response->assertOk();
    expect(session('active_org_id'))->toBe($this->org->id);
    $response->assertCookie('last_org_id', $this->org->id);
});
