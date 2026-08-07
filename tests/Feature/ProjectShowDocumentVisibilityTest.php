<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

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

it('includes the pending invitation on a kanban task assigned to one', function () {
    $orgAdmin = User::factory()->create();
    $this->org->users()->attach($orgAdmin->id, ['role' => 'org-admin']);

    \App\Models\DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'action_items',
        'label' => 'Action Items',
        'is_task' => true,
        'order' => 1,
    ]);

    $invitation = \App\Models\OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'kanban-invited@example.com',
        'first_name' => 'Kanban',
        'last_name' => 'Invitee',
        'token' => str_repeat('y', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $task = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A Task',
        'type' => 'action_items',
        'content' => 'Do the thing',
        'pending_assignee_invitation_id' => $invitation->id,
    ]);

    setPermissionsTeamId($this->org->id);

    $this->actingAs($orgAdmin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->where("kanbanData.{$this->project->id}.0.pending_assignee.email", 'kanban-invited@example.com')
            ->where("kanbanData.{$this->project->id}.0.pending_assignee.first_name", 'Kanban')
        );
});

it('includes the pending invitation on a document assigned to one', function () {
    $orgAdmin = User::factory()->create();
    $this->org->users()->attach($orgAdmin->id, ['role' => 'org-admin']);

    $invitation = \App\Models\OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'first_name' => 'Invited',
        'last_name' => 'Person',
        'token' => str_repeat('x', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->document->update(['pending_assignee_invitation_id' => $invitation->id]);

    setPermissionsTeamId($this->org->id);

    $this->actingAs($orgAdmin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->where('currentProject.documents.0.pending_assignee.email', 'invited@example.com')
            ->where('currentProject.documents.0.pending_assignee.first_name', 'Invited')
            ->where('currentProject.documents.0.pending_assignee.last_name', 'Person')
        );
});
