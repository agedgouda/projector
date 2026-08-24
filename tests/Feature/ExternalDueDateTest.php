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

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

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

    setPermissionsTeamId($this->org->id);
});

it('lets an org-admin toggle uses_external_due_dates on their organization', function () {
    expect($this->org->fresh()->uses_external_due_dates)->toBeFalse();

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'uses_external_due_dates' => true,
        ])
        ->assertRedirect();

    expect($this->org->fresh()->uses_external_due_dates)->toBeTrue();
});

it('persists external_due_at when updating a document\'s attributes', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up',
        'priority' => 'low',
        'task_status' => 'todo',
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $document]), [
            'external_due_at' => '2026-08-15',
        ])
        ->assertRedirect();

    expect($document->fresh()->external_due_at)->toStartWith('2026-08-15');
});

it('persists start_at when updating a document\'s attributes', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up',
        'priority' => 'low',
        'task_status' => 'todo',
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $document]), [
            'start_at' => '2026-08-10',
        ])
        ->assertRedirect();

    expect($document->fresh()->start_at)->toStartWith('2026-08-10');
});

it('leaves start_at untouched when updating an unrelated attribute', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up',
        'priority' => 'low',
        'task_status' => 'todo',
        'start_at' => '2026-08-10',
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $document]), [
            'priority' => 'high',
        ])
        ->assertRedirect();

    expect($document->fresh()->start_at)->toStartWith('2026-08-10');
});

it('persists external_due_at when creating a document', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), [
            'name' => 'New Task',
            'type' => 'task',
            'content' => 'Do the thing',
            'priority' => 'low',
            'task_status' => 'todo',
            'due_at' => '2026-08-01',
            'external_due_at' => '2026-08-15',
        ])
        ->assertRedirect();

    $document = Document::where('name', 'New Task')->firstOrFail();
    expect($document->external_due_at)->toStartWith('2026-08-15');
});

it('persists start_at when creating a document', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), [
            'name' => 'New Task',
            'type' => 'task',
            'content' => 'Do the thing',
            'priority' => 'low',
            'task_status' => 'todo',
            'start_at' => '2026-08-10',
            'due_at' => '2026-08-20',
        ])
        ->assertRedirect();

    $document = Document::where('name', 'New Task')->firstOrFail();
    expect($document->start_at)->toStartWith('2026-08-10');
});

it('shares uses_external_due_dates on the active org via orgMembership', function () {
    $this->org->update(['uses_external_due_dates' => true]);

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('orgMembership.uses_external_due_dates', true)
        );
});

it('shares uses_external_due_dates as false for an org that has not opted in', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('orgMembership.uses_external_due_dates', false)
        );
});
