<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Task;
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

    setPermissionsTeamId(null);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->member = User::factory()->create(); // no role
});

it('allows a super-admin to create a task', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'title' => 'Test Task',
            'status' => 'todo',
            'priority' => 'low',
        ])
        ->assertRedirect();

    expect(Task::count())->toBe(1);
});

it('redirects guests to login when accessing task store', function () {
    $this->post(route('tasks.store'), [
        'project_id' => $this->project->id,
        'title' => 'Test Task',
        'status' => 'todo',
        'priority' => 'low',
    ])->assertRedirectContains('login');
});

it('blocks a user without admin role from creating a task', function () {
    $this->actingAs($this->member)
        ->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'title' => 'Test Task',
            'status' => 'todo',
            'priority' => 'low',
        ])
        ->assertNotFound();
});

it('blocks a task with a document_id from a different project', function () {
    $otherProjectType = ProjectType::create(['name' => 'Other Type', 'document_schema' => []]);
    $otherClient = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Other Client',
        'contact_name' => 'Bob',
        'contact_phone' => '555-9999',
    ]);
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $otherClient->id,
        'project_type_id' => $otherProjectType->id,
    ]);
    $foreignDoc = Document::create([
        'project_id' => $otherProject->id,
        'name' => 'Foreign Doc',
        'type' => 'note',
        'content' => 'content',
    ]);

    $this->actingAs($this->superAdmin)
        ->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'document_id' => $foreignDoc->id,
            'title' => 'Bad Task',
            'status' => 'todo',
            'priority' => 'low',
        ])
        ->assertStatus(422);
});

it('blocks an org-admin from another org from creating a task in a different org project', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherOrgAdminRole = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);
    $otherAdmin = User::factory()->create();
    setPermissionsTeamId($otherOrg->id);
    $otherAdmin->organizations()->syncWithoutDetaching([$otherOrg->id]);
    $otherAdmin->assignRole($otherOrgAdminRole);

    $this->actingAs($otherAdmin)
        ->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'title' => 'Cross-Org Task',
            'status' => 'todo',
            'priority' => 'low',
        ])
        ->assertNotFound();
});

it('allows a super-admin to update a task', function () {
    $task = Task::create([
        'project_id' => $this->project->id,
        'title' => 'Original Title',
        'status' => 'todo',
        'priority' => 'low',
    ]);

    $this->actingAs($this->superAdmin)
        ->put(route('tasks.update', $task), ['title' => 'Updated Title'])
        ->assertRedirect();

    expect($task->fresh()->title)->toBe('Updated Title');
});

it('blocks a user without admin role from updating a task', function () {
    $task = Task::create([
        'project_id' => $this->project->id,
        'title' => 'Original Title',
        'status' => 'todo',
        'priority' => 'low',
    ]);

    $this->actingAs($this->member)
        ->put(route('tasks.update', $task), ['title' => 'Hacked Title'])
        ->assertNotFound();

    expect($task->fresh()->title)->toBe('Original Title');
});
