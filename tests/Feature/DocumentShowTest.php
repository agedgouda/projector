<?php

use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Models\WorkflowStep;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => false],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
    ]);

    $template = AiTemplate::create([
        'name' => 'Action Items to Task',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);

    WorkflowStep::create([
        'project_type_id' => $this->projectType->id,
        'from_key' => 'action_items',
        'to_key' => 'task',
        'ai_template_id' => $template->id,
        'order' => 1,
    ]);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);

    setPermissionsTeamId($this->org->id);
});

it('reports true when the locked protocol still has a next step for this document\'s type', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up',
        'processed_at' => now(),
        'locked_project_type_id' => $this->projectType->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.documents.show', [$this->project, $document]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Show')
            ->where('item.locked_next_workflow_step_exists', true)
        );
});

it('reports false when the locked protocol has no further step for this document\'s type', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Task 1',
        'type' => 'task',
        'content' => 'Do it',
        'processed_at' => now(),
        'locked_project_type_id' => $this->projectType->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.documents.show', [$this->project, $document]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Show')
            ->where('item.locked_next_workflow_step_exists', false)
        );
});

it('stamps content_updated_at when a document\'s content is edited', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Original content',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);

    expect($document->content_updated_at)->toBeNull();

    $this->actingAs($this->admin)
        ->put(route('projects.documents.update', [$this->project, $document]), [
            'name' => $document->name,
            'content' => 'Edited content',
            'priority' => 'low',
            'task_status' => 'todo',
        ])
        ->assertRedirect();

    expect($document->fresh()->content_updated_at)->not->toBeNull();
});

it('does not stamp content_updated_at when only sidebar attributes are patched', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Original content',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $document]), [
            'task_status' => 'done',
        ])
        ->assertRedirect();

    expect($document->fresh())
        ->task_status->toBe('done')
        ->content_updated_at->toBeNull();
});
