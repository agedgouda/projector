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
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

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

    $orgAdminRole = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    setPermissionsTeamId($this->org->id);
    $this->admin->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->admin->assignRole($orgAdminRole);
    $this->client->users()->attach($this->admin->id);
});

it('redirects to a valid relative path after project deletion', function () {
    $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $this->project), ['redirect_to' => '/projects'])
        ->assertRedirect('/projects');
});

it('falls back to dashboard when redirect_to is an absolute URL', function () {
    $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $this->project), ['redirect_to' => 'https://evil.com'])
        ->assertRedirect(route('dashboard'));
});

it('falls back to dashboard when redirect_to is a protocol-relative URL', function () {
    $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $this->project), ['redirect_to' => '//evil.com'])
        ->assertRedirect(route('dashboard'));
});

it('falls back to dashboard when no redirect_to is provided', function () {
    $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $this->project))
        ->assertRedirect(route('dashboard'));
});
