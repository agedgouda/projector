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

it('includes child document data, not just existence, so the detail page can link to them', function () {
    $parent = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up',
        'processed_at' => now(),
    ]);
    $child = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $parent->id,
        'name' => 'Task 1',
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.documents.show', [$this->project, $parent]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Show')
            ->where('item.children.0.id', $child->id)
            ->where('item.children.0.name', 'Task 1')
            ->where('item.children.0.type', 'task')
        );
});

it('orders children deterministically (newest first) instead of leaving row order up to Postgres', function () {
    // Without an explicit order, Postgres doesn't guarantee row order is stable across
    // repeated queries — and every field edit on the "Generated Tasks" list re-runs this
    // exact query. An unstable order meant rows could visually reshuffle after any edit,
    // so whichever row a user was about to click next could silently become a different
    // task by the time the click landed.
    //
    // created_at isn't in Document::$fillable (it's Eloquent-managed), so it can't be
    // backdated via create() here — $older and $newer instead get real, back-to-back
    // creation timestamps, which can legitimately land in the same second. That's exactly
    // why the controller's query breaks ties on `id` too: Document's HasUuids trait
    // generates time-ordered UUIDs, so `id` is a reliable, high-precision proxy for
    // creation order even when `created_at` itself ties.
    $parent = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up',
        'processed_at' => now(),
    ]);
    $older = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $parent->id,
        'name' => 'Older Task',
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);
    $newer = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $parent->id,
        'name' => 'Newer Task',
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.documents.show', [$this->project, $parent]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Show')
            ->where('item.children.0.id', $newer->id)
            ->where('item.children.1.id', $older->id)
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

it('flashes a "Task updated." confirmation for a task-type document', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A Task',
        'type' => 'task',
        'content' => '',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $document]), [
            'task_status' => 'done',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Task updated.');
});

it('flashes an "Event updated." confirmation for an event-type document, not "Task updated."', function () {
    $event = Document::create([
        'project_id' => $this->project->id,
        'name' => 'An Event',
        'type' => 'event',
        'content' => '',
        'due_at' => now()->addDay(),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $event]), [
            'due_at' => now()->addWeek()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Event updated.');

    expect($event->fresh()->due_at)->not->toBeNull();
});

it('rejects a task_status that is not one of the project\'s kanban columns', function () {
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
            'task_status' => 'not_a_real_column',
        ])
        ->assertSessionHasErrors('task_status');

    expect($document->fresh()->task_status)->toBe('todo');
});

it('accepts a project\'s custom kanban column as task_status', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Original content',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
    ]);
    $this->project->kanbanColumns()->create([
        'key' => 'blocked',
        'label' => 'Blocked',
        'color' => 'red',
        'order' => 5,
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.updateAttributes', [$this->project, $document]), [
            'task_status' => 'blocked',
        ])
        ->assertRedirect();

    expect($document->fresh()->task_status)->toBe('blocked');
});
