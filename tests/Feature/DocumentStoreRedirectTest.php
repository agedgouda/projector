<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    // Seeds the global document type catalog (via ProjectTypeObserver) — the store()
    // redirect target depends on whether the document's type resolves as is_task via that
    // catalog, not on the literal type string, per DocumentTypeCatalogGroupingTest.php.
    ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Meeting Notes', 'key' => 'action_items', 'is_task' => false],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
    ]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);
});

function storeDocumentPayload(string $type): array
{
    return [
        'name' => 'Save and New test',
        'type' => $type,
        'content' => 'Some content',
        'priority' => 'low',
        'task_status' => 'todo',
    ];
}

it('honors a relative redirect for a non-task document type ("Save and New")', function () {
    $redirect = "/projects/{$this->project->id}/documents/create?type=action_items";

    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project).'?'.http_build_query(['redirect' => $redirect]), storeDocumentPayload('action_items'))
        ->assertRedirect($redirect);
});

it('honors a relative redirect for a task document type', function () {
    $redirect = "/projects/{$this->project->id}/documents/create?type=task";

    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project).'?'.http_build_query(['redirect' => $redirect]), storeDocumentPayload('task'))
        ->assertRedirect($redirect);
});

it('falls back to the hierarchy tab for a non-task type when no redirect is given', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), storeDocumentPayload('action_items'))
        ->assertRedirect(route('projects.show', $this->project).'?tab=hierarchy');
});

it('falls back to the tasks tab for a task type when no redirect is given', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), storeDocumentPayload('task'))
        ->assertRedirect(route('projects.show', $this->project).'?tab=tasks');
});

it('ignores an absolute-url redirect and falls back to the default', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project).'?'.http_build_query(['redirect' => 'https://evil.example.com']), storeDocumentPayload('action_items'))
        ->assertRedirect(route('projects.show', $this->project).'?tab=hierarchy');
});

it('ignores a protocol-relative redirect and falls back to the default', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project).'?'.http_build_query(['redirect' => '//evil.example.com']), storeDocumentPayload('action_items'))
        ->assertRedirect(route('projects.show', $this->project).'?tab=hierarchy');
});
