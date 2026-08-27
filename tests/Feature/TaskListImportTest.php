<?php

use App\Events\TaskListImportProgress;
use App\Jobs\GenerateDocumentEmbedding;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function csvUpload(string $csv, string $name = 'tasks.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'tasklist').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/**
 * A genuine Excel date cell (as opposed to a CSV's plain date text) is stored as a numeric
 * serial with a date number-format applied — only reproducible with a real .xlsx file built
 * through PhpSpreadsheet, not a CSV fixture.
 */
function xlsxUploadWithDateCell(): UploadedFile
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Name', 'Due Date'], null, 'A1');
    $sheet->setCellValue('A2', 'Write report');
    $sheet->setCellValue('B2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime('2026-09-01')));
    $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_XLSX14_ACTUAL);

    $path = tempnam(sys_get_temp_dir(), 'tasklist').'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'tasks.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

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
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

// ── Analyze ──────────────────────────────────────────────────────────────────────

it('parses a csv upload and suggests a column mapping', function () {
    $csv = "Task Name,Priority,Status,Due Date,Assignee\nWrite report,High,In Progress,2026-09-01,jane@example.com\n";

    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload($csv),
        ]);

    $response->assertSuccessful();
    $response->assertJson([
        'headers' => ['Task Name', 'Priority', 'Status', 'Due Date', 'Assignee'],
        'suggested_mapping' => [
            'name' => 'Task Name',
            'priority' => 'Priority',
            'task_status' => 'Status',
            'due_at' => 'Due Date',
            'assignee' => 'Assignee',
        ],
    ]);
    expect($response->json('rows'))->toBe([
        ['Write report', 'High', 'In Progress', '2026-09-01', 'jane@example.com'],
    ]);
});

it('drops fully blank trailing rows when analyzing', function () {
    $csv = "Name\nFirst task\n,,\n";

    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload($csv),
        ]);

    $response->assertSuccessful();
    expect($response->json('rows'))->toBe([['First task']]);
});

it('drops trailing blank-header columns, keeping data rows aligned to the remaining headers', function () {
    // Mirrors a real Excel export: extra formatted-but-empty columns past the last real
    // header still show up in the sheet's used range as blank cells.
    $csv = "Name,Priority,,,\nWrite report,High,,,\n";

    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload($csv),
        ]);

    $response->assertSuccessful();
    $response->assertJson(['headers' => ['Name', 'Priority']]);
    expect($response->json('rows'))->toBe([['Write report', 'High']]);
});

it('drops a blank-header column in the middle, keeping the columns on either side aligned', function () {
    $csv = "Name,,Priority\nWrite report,,High\n";

    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload($csv),
        ]);

    $response->assertSuccessful();
    $response->assertJson(['headers' => ['Name', 'Priority']]);
    expect($response->json('rows'))->toBe([['Write report', 'High']]);
});

it('suggests a column mapping for event-list headers too, from the same analyze pass', function () {
    $csv = "Name,Description,Start Date,End Date\nKickoff,Project kickoff,2026-09-01,2026-09-03\n";

    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload($csv),
        ]);

    $response->assertSuccessful();
    $response->assertJson([
        'suggested_mapping' => [
            'name' => 'Name',
            'description' => 'Description',
            'start_date' => 'Start Date',
            'due_at' => 'End Date',
        ],
    ]);
});

it('suggests the tag mapping from a "Category" header', function () {
    $csv = "Name,Category\nKickoff,Partner\n";

    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload($csv),
        ]);

    $response->assertSuccessful();
    $response->assertJson(['suggested_mapping' => ['name' => 'Name', 'tag' => 'Category']]);
});

it('reads a genuine Excel date cell as a real date string, not its raw numeric serial', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => xlsxUploadWithDateCell(),
        ]);

    $response->assertSuccessful();
    $cell = $response->json('rows.0.1');
    expect($cell)->not->toBeNumeric()
        ->and(\Illuminate\Support\Carbon::parse($cell)->toDateString())->toBe('2026-09-01');
});

it('forbids an outsider from analyzing a spreadsheet', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('projects.task-lists.analyze', $this->project), [
            'file' => csvUpload("Name\nTask\n"),
        ])
        ->assertNotFound();
});

// ── Store ────────────────────────────────────────────────────────────────────────

function importPayload(array $overrides = []): array
{
    return array_merge([
        'list_type' => 'task',
        'original_filename' => 'tasks.csv',
        'headers' => ['Name', 'Priority', 'Status', 'Due Date', 'Assignee'],
        'rows' => [
            ['Write report', 'high', 'in_progress', '2026-09-01', 'jane@example.com'],
        ],
        'mapping' => [
            'name' => 'Name',
            'priority' => 'Priority',
            'task_status' => 'Status',
            'due_at' => 'Due Date',
            'assignee' => 'Assignee',
        ],
    ], $overrides);
}

function eventImportPayload(array $overrides = []): array
{
    return array_merge([
        'list_type' => 'event',
        'original_filename' => 'events.csv',
        'headers' => ['Name', 'Description', 'Start Date', 'End Date'],
        'rows' => [
            ['Kickoff Meeting', 'Project kickoff', '2026-09-01', '2026-09-03'],
        ],
        'mapping' => [
            'name' => 'Name',
            'description' => 'Description',
            'start_date' => 'Start Date',
            'due_at' => 'End Date',
        ],
    ], $overrides);
}

it('creates an import document and one task per row', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload())
        ->assertSuccessful();

    $import = Document::where('type', 'task_list_import')->first();
    $response->assertJson(['import_document_id' => $import->id, 'total' => 1]);
    expect($import)->not->toBeNull()
        ->and($import->name)->toBe('tasks.csv')
        ->and($import->metadata['created_count'])->toBe(1)
        ->and($import->metadata['status'])->toBe('completed');

    $decoded = json_decode($import->content, true);
    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['name'])->toBe('Write report');

    $task = Document::where('type', 'task')->where('name', 'Write report')->first();
    expect($task)->not->toBeNull()
        ->and($task->priority)->toBe('high')
        ->and($task->task_status)->toBe('in_progress')
        ->and($task->due_at)->not->toBeNull()
        ->and($task->metadata['imported_from'])->toBe($import->id);
});

it('never dispatches embedding generation for the import record itself', function () {
    // The import document's content is a JSON dump of every imported row (see
    // ImportTaskList::finish()), not human-readable text — embedding it is meaningless, and a
    // real-sized import routinely exceeds OpenAI's 8192-token embedding input limit, which
    // otherwise permanently fails GenerateDocumentEmbedding for every single import.
    Bus::fake([GenerateDocumentEmbedding::class]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload())
        ->assertSuccessful();

    $import = Document::where('type', 'task_list_import')->first();

    Bus::assertNotDispatched(GenerateDocumentEmbedding::class, fn ($job) => $job->document->id === $import->id);
});

it('broadcasts a final TaskListImportProgress event pointing at the tasks tab once the import finishes', function () {
    Event::fake([TaskListImportProgress::class]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload())
        ->assertSuccessful();

    $import = Document::where('type', 'task_list_import')->first();

    Event::assertDispatched(TaskListImportProgress::class, function (TaskListImportProgress $event) use ($import) {
        return $event->importDocument->id === $import->id
            && $event->status === 'done'
            && $event->processed === 1
            && $event->total === 1
            && $event->redirectUrl === route('projects.show', $this->project).'?tab=tasks'
            && $event->message === 'Imported 1 tasks.';
    });
});

it('broadcasts a running TaskListImportProgress update for each row, ahead of the final done event', function () {
    Event::fake([TaskListImportProgress::class]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [
                ['First task', 'high', 'todo', '', ''],
                ['Second task', 'low', 'todo', '', ''],
                ['Third task', 'medium', 'todo', '', ''],
            ],
        ]))
        ->assertSuccessful();

    $import = Document::where('type', 'task_list_import')->first();

    Event::assertDispatched(TaskListImportProgress::class, function (TaskListImportProgress $event) use ($import) {
        return $event->importDocument->id === $import->id
            && $event->status === 'running'
            && $event->processed === 2
            && $event->total === 3;
    });

    Event::assertDispatched(TaskListImportProgress::class, function (TaskListImportProgress $event) use ($import) {
        return $event->importDocument->id === $import->id
            && $event->status === 'done'
            && $event->processed === 3
            && $event->total === 3;
    });
});

it('matches an assignee by exact email', function () {
    $member = User::factory()->create(['email' => 'jane@example.com']);
    $this->org->users()->attach($member->id, ['role' => 'member']);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload())
        ->assertSuccessful();

    $task = Document::where('type', 'task')->first();
    expect($task->assignee_id)->toBe($member->id)
        ->and($task->pending_assignee_invitation_id)->toBeNull();
});

it('matches an assignee by full name against a pending invitation', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'someone@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'role' => 'member',
        'token' => Str::random(32),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'in_progress', '2026-09-01', 'Jane Smith']],
        ]))
        ->assertSuccessful();

    $task = Document::where('type', 'task')->first();
    expect($task->assignee_id)->toBeNull()
        ->and($task->pending_assignee_invitation_id)->toBe($invitation->id);
});

it('leaves a task unassigned when the assignee text matches no one', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'in_progress', '2026-09-01', 'Nobody Here']],
        ]))
        ->assertSuccessful();

    $task = Document::where('type', 'task')->first();
    expect($task->assignee_id)->toBeNull()
        ->and($task->pending_assignee_invitation_id)->toBeNull();
});

it('tags an imported task by matching the tag column against an existing project tag', function () {
    $tag = Category::create(['project_id' => $this->project->id, 'name' => 'Partner', 'color' => 'pink']);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'headers' => ['Name', 'Priority', 'Status', 'Due Date', 'Assignee', 'Category'],
            'rows' => [['Write report', 'high', 'in_progress', '2026-09-01', '', 'Partner']],
            'mapping' => [
                'name' => 'Name',
                'priority' => 'Priority',
                'task_status' => 'Status',
                'due_at' => 'Due Date',
                'assignee' => 'Assignee',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    $task = Document::where('type', 'task')->first();
    expect($task->categories()->pluck('categories.id')->all())->toBe([$tag->id]);
});

it('matches a tag case-insensitively', function () {
    $tag = Category::create(['project_id' => $this->project->id, 'name' => 'Partner', 'color' => 'pink']);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'headers' => ['Name', 'Priority', 'Status', 'Due Date', 'Assignee', 'Category'],
            'rows' => [['Write report', 'high', 'in_progress', '', '', 'PARTNER']],
            'mapping' => [
                'name' => 'Name',
                'priority' => 'Priority',
                'task_status' => 'Status',
                'due_at' => 'Due Date',
                'assignee' => 'Assignee',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    $task = Document::where('type', 'task')->first();
    expect($task->categories()->pluck('categories.id')->all())->toBe([$tag->id]);
});

it('creates a new tag when the tag text matches no existing project tag', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'headers' => ['Name', 'Priority', 'Status', 'Due Date', 'Assignee', 'Category'],
            'rows' => [['Write report', 'high', 'in_progress', '', '', 'Partner']],
            'mapping' => [
                'name' => 'Name',
                'priority' => 'Priority',
                'task_status' => 'Status',
                'due_at' => 'Due Date',
                'assignee' => 'Assignee',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    $created = Category::where('project_id', $this->project->id)->where('name', 'Partner')->first();
    expect($created)->not->toBeNull();

    $task = Document::where('type', 'task')->first();
    expect($task->categories()->pluck('categories.id')->all())->toBe([$created->id]);
});

it('reuses a tag it just created for a later row in the same import, instead of creating a duplicate', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'headers' => ['Name', 'Priority', 'Status', 'Due Date', 'Assignee', 'Category'],
            'rows' => [
                ['First task', 'high', 'in_progress', '', '', 'Partner'],
                ['Second task', 'low', 'todo', '', '', 'partner'],
            ],
            'mapping' => [
                'name' => 'Name',
                'priority' => 'Priority',
                'task_status' => 'Status',
                'due_at' => 'Due Date',
                'assignee' => 'Assignee',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    expect(Category::where('project_id', $this->project->id)->where('name', 'Partner')->count())->toBe(1);

    $tasks = Document::where('type', 'task')->get();
    expect($tasks)->toHaveCount(2);
    foreach ($tasks as $task) {
        expect($task->categories()->count())->toBe(1);
    }
});

it('leaves a task untagged, without failing the import, when every one of the family\'s 10 tag colors is already taken', function () {
    $palette = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];
    foreach ($palette as $i => $color) {
        Category::create(['project_id' => $this->project->id, 'name' => "Existing {$i}", 'color' => $color]);
    }

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'headers' => ['Name', 'Priority', 'Status', 'Due Date', 'Assignee', 'Category'],
            'rows' => [['Write report', 'high', 'in_progress', '', '', 'Brand New Tag']],
            'mapping' => [
                'name' => 'Name',
                'priority' => 'Priority',
                'task_status' => 'Status',
                'due_at' => 'Due Date',
                'assignee' => 'Assignee',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    $task = Document::where('type', 'task')->first();
    expect($task)->not->toBeNull()
        ->and($task->categories()->count())->toBe(0)
        ->and(Category::where('project_id', $this->project->id)->count())->toBe(10);
});

it('skips a row with no name instead of failing the whole import', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [
                ['', 'high', 'in_progress', '', ''],
                ['Second task', 'low', 'todo', '', ''],
            ],
        ]))
        ->assertSuccessful();

    expect(Document::where('type', 'task')->count())->toBe(1);

    $import = Document::where('type', 'task_list_import')->first();
    expect($import->metadata['created_count'])->toBe(1)
        ->and($import->metadata['status'])->toBe('completed_with_errors')
        ->and($import->metadata['skipped'])->toHaveCount(1);
});

it('falls back to medium priority for an unrecognized priority value', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'urgent!!', 'in_progress', '', '']],
        ]))
        ->assertSuccessful();

    expect(Document::where('type', 'task')->first()->priority)->toBe('medium');
});

it('falls back to the project\'s first kanban column for an unrecognized status value', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'Some Made Up Column', '', '']],
        ]))
        ->assertSuccessful();

    expect(Document::where('type', 'task')->first()->task_status)->toBe('todo');
});

it('matches a status by its human label as well as its key', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'In Review', '', '']],
        ]))
        ->assertSuccessful();

    expect(Document::where('type', 'task')->first()->task_status)->toBe('review');
});

it('requires a name column mapping', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload([
            'mapping' => ['name' => null, 'priority' => 'Priority'],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('mapping.name');
});

it('forbids an outsider from importing a task list', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload())
        ->assertNotFound();
});

it('requires a list_type of task or event', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), importPayload(['list_type' => 'bogus']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('list_type');
});

// ── Store: events ────────────────────────────────────────────────────────────────

it('creates an import document and one event per row', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload())
        ->assertSuccessful();

    $import = Document::where('type', 'event_list_import')->first();
    $response->assertJson(['import_document_id' => $import->id, 'total' => 1]);
    expect($import)->not->toBeNull()
        ->and($import->name)->toBe('events.csv')
        ->and($import->metadata['created_count'])->toBe(1)
        ->and($import->metadata['status'])->toBe('completed');

    $event = Document::where('type', 'event')->where('name', 'Kickoff Meeting')->first();
    expect($event)->not->toBeNull()
        ->and($event->content)->toBe('Project kickoff')
        ->and($event->start_at)->not->toBeNull()->toStartWith('2026-09-01')
        ->and($event->due_at)->not->toBeNull()->toStartWith('2026-09-03')
        ->and($event->metadata['imported_from'])->toBe($import->id);
});

it('broadcasts a final TaskListImportProgress event pointing at the calendar tab once the import finishes', function () {
    Event::fake([TaskListImportProgress::class]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload())
        ->assertSuccessful();

    $import = Document::where('type', 'event_list_import')->first();

    Event::assertDispatched(TaskListImportProgress::class, function (TaskListImportProgress $event) use ($import) {
        return $event->importDocument->id === $import->id
            && $event->status === 'done'
            && $event->processed === 1
            && $event->total === 1
            && $event->redirectUrl === route('projects.show', $this->project).'?tab=calendar'
            && $event->message === 'Imported 1 events.';
    });
});

it('tags an imported event by matching the tag column against an existing project tag', function () {
    $tag = Category::create(['project_id' => $this->project->id, 'name' => 'Partner', 'color' => 'pink']);

    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload([
            'headers' => ['Name', 'Description', 'Start Date', 'End Date', 'Category'],
            'rows' => [['Kickoff Meeting', 'Project kickoff', '2026-09-01', '2026-09-03', 'Partner']],
            'mapping' => [
                'name' => 'Name',
                'description' => 'Description',
                'start_date' => 'Start Date',
                'due_at' => 'End Date',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    $event = Document::where('type', 'event')->first();
    expect($event->categories()->pluck('categories.id')->all())->toBe([$tag->id]);
});

it('creates a new tag for an imported event when the tag text matches no existing project tag', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload([
            'headers' => ['Name', 'Description', 'Start Date', 'End Date', 'Category'],
            'rows' => [['Kickoff Meeting', 'Project kickoff', '2026-09-01', '2026-09-03', 'Partner']],
            'mapping' => [
                'name' => 'Name',
                'description' => 'Description',
                'start_date' => 'Start Date',
                'due_at' => 'End Date',
                'tag' => 'Category',
            ],
        ]))
        ->assertSuccessful();

    $created = Category::where('project_id', $this->project->id)->where('name', 'Partner')->first();
    expect($created)->not->toBeNull();

    $event = Document::where('type', 'event')->first();
    expect($event->categories()->pluck('categories.id')->all())->toBe([$created->id]);
});

it('treats a row with only a start date as a one-day event', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload([
            'rows' => [['Kickoff Meeting', '', '2026-09-01', '']],
        ]))
        ->assertSuccessful();

    $event = Document::where('type', 'event')->first();
    expect($event->start_at)->toStartWith('2026-09-01')
        ->and($event->due_at)->toStartWith('2026-09-01');
});

it('treats a row with only an end date as a one-day event', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload([
            'rows' => [['Kickoff Meeting', '', '', '2026-09-03']],
        ]))
        ->assertSuccessful();

    $event = Document::where('type', 'event')->first();
    expect($event->start_at)->toStartWith('2026-09-03')
        ->and($event->due_at)->toStartWith('2026-09-03');
});

it('skips an event row with no name instead of failing the whole import', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload([
            'rows' => [
                ['', '', '2026-09-01', ''],
                ['Second event', '', '2026-09-02', ''],
            ],
        ]))
        ->assertSuccessful();

    expect(Document::where('type', 'event')->count())->toBe(1);

    $import = Document::where('type', 'event_list_import')->first();
    expect($import->metadata['created_count'])->toBe(1)
        ->and($import->metadata['status'])->toBe('completed_with_errors')
        ->and($import->metadata['skipped'])->toHaveCount(1);
});

it('leaves an event undated when neither date column has a value', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.task-lists.store', $this->project), eventImportPayload([
            'rows' => [['Kickoff Meeting', '', '', '']],
        ]))
        ->assertSuccessful();

    $event = Document::where('type', 'event')->first();
    expect($event->start_at)->toBeNull()
        ->and($event->due_at)->toBeNull();
});
