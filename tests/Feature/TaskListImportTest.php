<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function csvUpload(string $csv, string $name = 'tasks.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'tasklist').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, $name, 'text/csv', null, true);
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

it('creates an import document and one task per row', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload())
        ->assertRedirect();

    $import = Document::where('type', 'task_list_import')->first();
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

it('matches an assignee by exact email', function () {
    $member = User::factory()->create(['email' => 'jane@example.com']);
    $this->org->users()->attach($member->id, ['role' => 'member']);

    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload())
        ->assertRedirect();

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
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'in_progress', '2026-09-01', 'Jane Smith']],
        ]))
        ->assertRedirect();

    $task = Document::where('type', 'task')->first();
    expect($task->assignee_id)->toBeNull()
        ->and($task->pending_assignee_invitation_id)->toBe($invitation->id);
});

it('leaves a task unassigned when the assignee text matches no one', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'in_progress', '2026-09-01', 'Nobody Here']],
        ]))
        ->assertRedirect();

    $task = Document::where('type', 'task')->first();
    expect($task->assignee_id)->toBeNull()
        ->and($task->pending_assignee_invitation_id)->toBeNull();
});

it('skips a row with no name instead of failing the whole import', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [
                ['', 'high', 'in_progress', '', ''],
                ['Second task', 'low', 'todo', '', ''],
            ],
        ]))
        ->assertRedirect();

    expect(Document::where('type', 'task')->count())->toBe(1);

    $import = Document::where('type', 'task_list_import')->first();
    expect($import->metadata['created_count'])->toBe(1)
        ->and($import->metadata['status'])->toBe('completed_with_errors')
        ->and($import->metadata['skipped'])->toHaveCount(1);
});

it('falls back to medium priority for an unrecognized priority value', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'urgent!!', 'in_progress', '', '']],
        ]))
        ->assertRedirect();

    expect(Document::where('type', 'task')->first()->priority)->toBe('medium');
});

it('falls back to the project\'s first kanban column for an unrecognized status value', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'Some Made Up Column', '', '']],
        ]))
        ->assertRedirect();

    expect(Document::where('type', 'task')->first()->task_status)->toBe('todo');
});

it('matches a status by its human label as well as its key', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'rows' => [['Write report', 'high', 'In Review', '', '']],
        ]))
        ->assertRedirect();

    expect(Document::where('type', 'task')->first()->task_status)->toBe('review');
});

it('requires a name column mapping', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.task-lists.store', $this->project), importPayload([
            'mapping' => ['name' => null, 'priority' => 'Priority'],
        ]))
        ->assertSessionHasErrors('mapping.name');
});

it('forbids an outsider from importing a task list', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('projects.task-lists.store', $this->project), importPayload())
        ->assertNotFound();
});
