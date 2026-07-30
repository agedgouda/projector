<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\KanbanColumn;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
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
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->member = User::factory()->create(); // no role
});

it('seeds 4 default columns when a project is created', function () {
    expect($this->project->kanbanColumns()->pluck('key'))
        ->toEqual(collect(['todo', 'in_progress', 'review', 'done']));
});

it('adds a kanban column with a generated key and the next order', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.kanban-columns.store', $this->project), ['label' => 'Blocked'])
        ->assertRedirect();

    $column = KanbanColumn::where('project_id', $this->project->id)->latest('id')->first();

    expect($column->key)->toBe('blocked');
    expect($column->label)->toBe('Blocked');
    expect($column->order)->toBe(5);
});

it('deduplicates the key when a column label slugs to an existing key', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.kanban-columns.store', $this->project), ['label' => 'To Do']);

    $column = KanbanColumn::where('project_id', $this->project->id)->latest('id')->first();

    expect($column->key)->toBe('to_do');
    expect($this->project->kanbanColumns()->where('key', 'todo')->exists())->toBeTrue();
});

it('renames a column without touching its key, so existing tasks stay linked', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
        'task_status' => 'todo',
    ]);
    $column = $this->project->kanbanColumns()->where('key', 'todo')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(route('projects.kanban-columns.update', [$this->project, $column]), ['label' => 'Backlog'])
        ->assertRedirect();

    expect($column->fresh())->key->toBe('todo')->label->toBe('Backlog');
    expect($document->fresh()->task_status)->toBe('todo');
});

it('deletes an empty column', function () {
    $column = $this->project->kanbanColumns()->where('key', 'review')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('projects.kanban-columns.destroy', [$this->project, $column]))
        ->assertRedirect();

    expect(KanbanColumn::find($column->id))->toBeNull();
});

it('blocks deleting a column that still has documents in it', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
        'task_status' => 'review',
    ]);
    $column = $this->project->kanbanColumns()->where('key', 'review')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('projects.kanban-columns.destroy', [$this->project, $column]))
        ->assertStatus(302)
        ->assertSessionHasErrors('kanban_column');

    expect(KanbanColumn::find($column->id))->not->toBeNull();
});

it('blocks deleting a column that still has tasks in it', function () {
    Task::create([
        'project_id' => $this->project->id,
        'title' => 'A task',
        'status' => 'review',
        'priority' => 'low',
    ]);
    $column = $this->project->kanbanColumns()->where('key', 'review')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('projects.kanban-columns.destroy', [$this->project, $column]))
        ->assertStatus(302)
        ->assertSessionHasErrors('kanban_column');

    expect(KanbanColumn::find($column->id))->not->toBeNull();
});

it('blocks a user without admin role from adding, renaming, or deleting a column', function () {
    $column = $this->project->kanbanColumns()->where('key', 'todo')->firstOrFail();

    $this->actingAs($this->member)
        ->post(route('projects.kanban-columns.store', $this->project), ['label' => 'Blocked'])
        ->assertNotFound();

    $this->actingAs($this->member)
        ->patch(route('projects.kanban-columns.update', [$this->project, $column]), ['label' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($this->member)
        ->delete(route('projects.kanban-columns.destroy', [$this->project, $column]))
        ->assertNotFound();
});
