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
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->project = Project::create([
        'name' => 'Parent Project',
        'client_id' => $this->client->id,
    ]);

    $this->child = Project::create([
        'name' => 'Sub Project',
        'client_id' => $this->client->id,
        'parent_id' => $this->project->id,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    setPermissionsTeamId($this->org->id);
});

it('includes a dated document from the project itself', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents', 'children.documents'])->calendarItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()['name'])->toBe('Own Task')
        ->and($items->first()['is_subproject'])->toBeFalse();
});

it('includes a dated document from a direct sub-project, tagged as a sub-project', function () {
    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-05',
    ]);

    $items = $this->project->fresh()->load(['documents', 'children.documents'])->calendarItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()['name'])->toBe('Sub Task')
        ->and($items->first()['is_subproject'])->toBeTrue()
        ->and($items->first()['project_name'])->toBe('Sub Project');
});

it('excludes documents with no due date set at all', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'No Date',
        'type' => 'task',
        'content' => 'Do it',
    ]);

    $items = $this->project->fresh()->load(['documents', 'children.documents'])->calendarItems();

    expect($items)->toHaveCount(0);
});

it('exposes calendarItems on the project show page, including sub-project items', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Task',
        'type' => 'task',
        'content' => 'Do it',
        'external_due_at' => '2026-09-10',
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->has('calendarItems', 2)
        );
});

it('downloads a calendar pdf with a 200 response', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportPdf', $this->project).'?month=2026-09')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('downloads a calendar csv including sub-project items by default', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-05',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Own Task')
        ->toContain('Sub Task')
        ->toContain('Sub Project');
});

it('excludes a hidden sub-project from the calendar csv export', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-05',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09&hidden_subprojects[]='.$this->child->id)
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Own Task')
        ->not->toContain('Sub Task');
});

it('only includes items due in the requested month', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'September Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'October Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-10-01',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('September Task')
        ->toContain('September 2026')
        ->not->toContain('October Task');
});

it('defaults to the current month when none is requested', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Today Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project))
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Today Task')
        ->toContain(now()->format('F Y'));
});
