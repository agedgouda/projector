<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\PendingImport;
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
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);
});

function makePendingImport(Project $project): PendingImport
{
    return PendingImport::create([
        'project_id' => $project->id,
        'original_filename' => 'export.csv',
        'source_type' => 'spreadsheet',
        'source' => 'slack',
        'note' => 'Uploaded via Slack — needs review.',
    ]);
}

it('shares the count of pending imports in the active org for an org-admin', function () {
    makePendingImport($this->project);
    makePendingImport($this->project);

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pendingImportsCount', 2));
});

it('shares zero when there are no pending imports', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pendingImportsCount', 0));
});

it('does not count a pending import belonging to a different organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create(['name' => 'Other Project', 'client_id' => $otherClient->id]);
    makePendingImport($otherProject);

    makePendingImport($this->project);

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pendingImportsCount', 1));
});

it('shares zero for a member with no manage-imports role, even when the org has pending imports', function () {
    makePendingImport($this->project);

    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pendingImportsCount', 0));
});

it('shares the count for a project-lead', function () {
    makePendingImport($this->project);

    $lead = User::factory()->create();
    $this->org->users()->attach($lead->id, ['role' => 'project-lead']);

    $this->actingAs($lead)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pendingImportsCount', 1));
});
