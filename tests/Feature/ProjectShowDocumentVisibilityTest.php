<?php

use App\Models\Client;
use App\Models\Document;
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
    $this->document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Intake Document',
        'type' => 'intake',
        'content' => 'Test content',
    ]);
});

it('includes documents in currentProject for super-admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    setPermissionsTeamId($this->org->id);

    $this->actingAs($superAdmin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->has('currentProject.documents', 1)
            ->where('currentProject.documents.0.name', 'Intake Document')
        );
});

it('includes documents in currentProject for org-admin', function () {
    $orgAdmin = User::factory()->create();
    $this->org->users()->attach($orgAdmin->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);

    $this->actingAs($orgAdmin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->has('currentProject.documents', 1)
            ->where('currentProject.documents.0.name', 'Intake Document')
        );
});
