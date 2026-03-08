<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $projectType = ProjectType::create(['name' => 'General', 'document_schema' => []]);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $projectType->id,
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
