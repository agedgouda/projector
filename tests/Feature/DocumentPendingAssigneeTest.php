<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
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

    ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
    ]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);

    $this->invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'first_name' => 'Invited',
        'last_name' => 'Person',
        'token' => str_repeat('a', 64),
        'expires_at' => now()->addDays(7),
    ]);
});

function taskPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Pending assignee test',
        'type' => 'task',
        'content' => 'Some content',
        'priority' => 'low',
        'task_status' => 'todo',
    ], $overrides);
}

it('assigns a new task to a pending invitee without crashing', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), taskPayload([
            'assignee_id' => "inv:{$this->invitation->id}",
        ]))
        ->assertRedirect();

    $document = Document::firstWhere('name', 'Pending assignee test');
    expect($document->assignee_id)->toBeNull()
        ->and($document->pending_assignee_invitation_id)->toBe($this->invitation->id);
});

it('rejects an inv: assignee id from another organization on create', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherInvitation = OrganizationInvitation::create([
        'organization_id' => $otherOrg->id,
        'email' => 'outsider@example.com',
        'token' => str_repeat('b', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), taskPayload([
            'assignee_id' => "inv:{$otherInvitation->id}",
        ]))
        ->assertSessionHasErrors('pending_assignee_invitation_id');
});

it('still assigns a real user id on create', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), taskPayload([
            'assignee_id' => $this->admin->id,
        ]))
        ->assertRedirect();

    $document = Document::firstWhere('name', 'Pending assignee test');
    expect($document->assignee_id)->toBe($this->admin->id)
        ->and($document->pending_assignee_invitation_id)->toBeNull();
});

it('assigns a pending invitee on full update', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Existing task',
        'type' => 'task',
        'content' => 'x',
        'priority' => 'low',
        'task_status' => 'todo',
    ]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.update', [$this->project, $document]), taskPayload([
            'name' => 'Existing task',
            'assignee_id' => "inv:{$this->invitation->id}",
        ]))
        ->assertRedirect();

    $document->refresh();
    expect($document->assignee_id)->toBeNull()
        ->and($document->pending_assignee_invitation_id)->toBe($this->invitation->id);
});
