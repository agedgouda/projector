<?php

use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
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

    // Registers 'task' as a task type in this org's document-type catalog, the same way a
    // real protocol's document_schema would (see ProjectTypeObserver) — Project::calendarItems()
    // now includes tasks via the catalog's is_task flag, not a hardcoded 'task' string.
    DocumentTypeDefinition::create([
        'organization_id' => $this->org->id,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);
});

it('includes a dated event document from the project itself', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()['name'])->toBe('Own Event')
        ->and($items->first()['is_subproject'])->toBeFalse();
});

it('includes a dated task document alongside events', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()['name'])->toBe('Own Task')
        ->and($items->first()['is_task'])->toBeTrue();
});

it('flags an event calendar item as not a task', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items->first()['is_task'])->toBeFalse();
});

it('excludes a document type that is neither an event nor flagged is_task in the catalog', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Some Notes',
        'type' => 'notes',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items)->toHaveCount(0);
});

it('includes a document whose type is flagged is_task under a key other than the literal "task"', function () {
    DocumentTypeDefinition::create([
        'organization_id' => $this->org->id,
        'key' => 'action_item',
        'label' => 'Action Item',
        'is_task' => true,
        'order' => 2,
    ]);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Custom Task Type',
        'type' => 'action_item',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()['is_task'])->toBeTrue();
});

it('includes a dated event document from a direct sub-project, tagged as a sub-project', function () {
    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-05',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()['name'])->toBe('Sub Event')
        ->and($items->first()['is_subproject'])->toBeTrue()
        ->and($items->first()['project_name'])->toBe('Sub Project');
});

it('exposes start_at on a calendar item that has one set', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Spanning Event',
        'type' => 'event',
        'content' => 'Do it',
        'start_at' => '2026-09-01',
        'due_at' => '2026-09-05',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items->first()['start_at'])->toStartWith('2026-09-01');
});

it('exposes a null start_at on a calendar item that has none set', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items->first()['start_at'])->toBeNull();
});

it('exposes the event document\'s tag on the calendar item', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Tagged Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Launch', 'color' => 'pink']);
    $document->categories()->attach($category->id);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items->first()['categories'])->toHaveCount(1)
        ->and($items->first()['categories'][0]['id'])->toBe($category->id)
        ->and($items->first()['categories'][0]['name'])->toBe('Launch');
});

it('excludes documents with no due date set at all', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'No Date',
        'type' => 'event',
        'content' => 'Do it',
    ]);

    $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

    expect($items)->toHaveCount(0);
});

it('exposes calendarItems on the project show page, including sub-project items', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Event',
        'type' => 'event',
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
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportPdf', $this->project).'?month=2026-09')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('downloads a calendar excel workbook with a 200 response', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportExcel', $this->project).'?month=2026-09')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('lays out the calendar csv as flat Date/Title/Tags rows, joining multiple tags', function () {
    $task = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Multi-tag Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    $launch = Category::create(['project_id' => $this->project->id, 'name' => 'Launch', 'color' => 'pink']);
    $press = Category::create(['project_id' => $this->project->id, 'name' => 'Press', 'color' => 'blue']);
    $task->categories()->attach([$launch->id, $press->id]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain("Date,Title,Tags\n")
        ->toContain('"Sep 1, 2026","Multi-tag Task","Launch, Press"');
});

it('lays out the calendar excel workbook as flat Date/Title/Tags rows', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Launch', 'color' => 'pink']);
    $document->categories()->attach($category->id);

    $xlsxBytes = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportExcel', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmpFile, $xlsxBytes);
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile)->getActiveSheet();
    unlink($tmpFile);

    expect($sheet->getCell('A2')->getValue())->toBe('Date')
        ->and($sheet->getCell('B2')->getValue())->toBe('Title')
        ->and($sheet->getCell('C2')->getValue())->toBe('Tags')
        ->and($sheet->getCell('A3')->getValue())->toBe('Sep 1, 2026')
        ->and($sheet->getCell('B3')->getValue())->toBe('Own Event')
        ->and($sheet->getCell('C3')->getValue())->toBe('Launch');
});

it('downloads a calendar csv including sub-project items by default', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-05',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Date,Title,Tags')
        ->toContain('Own Event')
        ->toContain('Sub Event');
});

it('excludes a hidden sub-project from the calendar csv export', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->child->id,
        'name' => 'Sub Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-05',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09&hidden_subprojects[]='.$this->child->id)
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Own Event')
        ->not->toContain('Sub Event');
});

it('only includes items due in the requested month', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'September Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'October Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-10-01',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('September Event')
        ->toContain('Sep 1, 2026')
        ->not->toContain('October Event');
});

it('defaults to the current month when none is requested', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Today Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project))
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Today Event')
        ->toContain(now()->format('M j, Y'));
});

it('filters the calendar csv export down to events with a selected tag', function () {
    $launch = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Launch Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Launch', 'color' => 'pink']);
    $launch->categories()->attach($category->id);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Untagged Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-02',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09&tags[]='.$category->id)
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Launch Event')
        ->not->toContain('Untagged Event');
});

it('filters the calendar csv export down to untagged events via the "none" tag value', function () {
    $launch = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Launch Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Launch', 'color' => 'pink']);
    $launch->categories()->attach($category->id);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Untagged Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-02',
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09&tags[]=none')
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Untagged Event')
        ->not->toContain('Launch Event');
});

it('includes both tasks and events in the csv export by default', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-02',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Own Task')
        ->and($csv)->toContain('Own Event');
});

it('excludes tasks from the csv export when show_tasks is false', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-02',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09&show_tasks=0')
        ->assertOk()
        ->streamedContent();

    expect($csv)->not->toContain('Own Task')
        ->and($csv)->toContain('Own Event');
});

it('excludes events from the csv export when show_events is false', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Task',
        'type' => 'task',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Own Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-02',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09&show_events=0')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Own Task')
        ->and($csv)->not->toContain('Own Event');
});

it('excludes an item that only has external_due_at when the org does not use external due dates', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'External Only Event',
        'type' => 'event',
        'content' => 'Do it',
        'external_due_at' => '2026-09-01',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    expect($csv)->not->toContain('External Only Event');
});

it('includes an item that only has external_due_at when the org uses external due dates', function () {
    $this->org->update(['uses_external_due_dates' => true]);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'External Only Event',
        'type' => 'event',
        'content' => 'Do it',
        'external_due_at' => '2026-09-01',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('External Only Event');
});

it('positions an item by external_due_at, not due_at, when the org uses external due dates', function () {
    $this->org->update(['uses_external_due_dates' => true]);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Dual Date Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
        'external_due_at' => '2026-10-01',
    ]);

    $septemberCsv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    $octoberCsv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-10')
        ->assertOk()
        ->streamedContent();

    expect($septemberCsv)->not->toContain('Dual Date Event')
        ->and($octoberCsv)->toContain('Dual Date Event');
});

it('still includes an item that only has due_at when the org uses external due dates', function () {
    $this->org->update(['uses_external_due_dates' => true]);

    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Due At Only Event',
        'type' => 'event',
        'content' => 'Do it',
        'due_at' => '2026-09-01',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('projects.calendar.exportCsv', $this->project).'?month=2026-09')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Due At Only Event');
});
