<?php

use App\Contracts\LlmDriver;
use App\Jobs\CreateTaskFromSlackCommand;
use App\Jobs\ExtractTextRecords;
use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\ProjectAiService;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org', 'uses_task_start_dates' => true]);
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

    $this->task = fn (string $name): ?Document => Document::where('type', 'task')->where('name', $name)->first();
    $this->start = fn (string $name): ?string => substr((string) ($this->task)($name)?->start_at, 0, 10) ?: null;
});

describe('spreadsheet import', function () {
    beforeEach(function () {
        $this->kickoff = Document::create([
            'project_id' => $this->project->id, 'name' => 'Kickoff', 'type' => 'task',
            'content' => 'x', 'priority' => 'low', 'task_status' => 'todo', 'due_at' => '2026-08-31',
        ]);

        $this->payload = [
            'list_type' => 'task',
            'original_filename' => 'plan.csv',
            'headers' => ['Name', 'Start Date', 'Due Date', 'Predecessor'],
            'rows' => [
                ['Build', '', '2026-09-14', 'Design'],
                ['Design', '2026-09-01', '2026-09-10', ''],
                ['Test', '', '2026-09-16', 'build'],
                ['Plan', '', '2026-09-03', 'KICKOFF'],
                ['Ghost', '', '2026-09-20', 'Nonexistent'],
            ],
            'mapping' => [
                'name' => 'Name',
                'start_date' => 'Start Date',
                'due_at' => 'Due Date',
                'predecessor' => 'Predecessor',
            ],
        ];
    });

    it('imports start dates and links each task to its predecessor by name', function () {
        $this->actingAs($this->admin)
            ->postJson(route('projects.task-lists.store', $this->project), $this->payload)
            ->assertSuccessful();

        expect(($this->task)('Build')->predecessor_id)->toBe(($this->task)('Design')->id)
            ->and(($this->task)('Test')->predecessor_id)->toBe(($this->task)('Build')->id)
            ->and(($this->task)('Plan')->predecessor_id)->toBe($this->kickoff->id)
            ->and(($this->task)('Ghost')->predecessor_id)->toBeNull()
            ->and(($this->start)('Design'))->toBe('2026-09-01')
            // A row naming a task further down the sheet still links, and starts when it ends.
            ->and(($this->start)('Build'))->toBe('2026-09-10')
            ->and(($this->start)('Test'))->toBe('2026-09-14')
            ->and(($this->start)('Plan'))->toBe('2026-08-31');

        $import = Document::where('type', 'task_list_import')->first();
        expect($import->metadata['unlinked'])->toHaveCount(1)
            ->and($import->metadata['unlinked'][0])->toMatchArray(['row' => 6, 'task' => 'Ghost', 'predecessor' => 'Nonexistent'])
            ->and(json_decode($import->content, true)[0])->toMatchArray(['name' => 'Build', 'predecessor' => 'Design']);
    });

    it('reports a predecessor that would loop instead of linking it', function () {
        $this->payload['rows'] = [
            ['A', '', '2026-09-10', 'B'],
            ['B', '', '2026-09-12', 'A'],
        ];

        $this->actingAs($this->admin)
            ->postJson(route('projects.task-lists.store', $this->project), $this->payload)
            ->assertSuccessful();

        expect(($this->task)('A')->predecessor_id)->toBe(($this->task)('B')->id)
            ->and(($this->task)('B')->predecessor_id)->toBeNull();

        $unlinked = Document::where('type', 'task_list_import')->first()->metadata['unlinked'];
        expect($unlinked)->toHaveCount(1)
            ->and($unlinked[0]['reason'])->toContain('wait on each other');
    });

    it('ignores start dates and predecessors when the org does not track task start dates', function () {
        $this->org->update(['uses_task_start_dates' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('projects.task-lists.store', $this->project), $this->payload)
            ->assertSuccessful();

        expect(Document::where('type', 'task')->whereNotNull('predecessor_id')->exists())->toBeFalse()
            ->and(($this->start)('Design'))->toBeNull();
    });

    it('suggests the Predecessor column when analyzing a spreadsheet', function () {
        $path = tempnam(sys_get_temp_dir(), 'tasklist').'.csv';
        file_put_contents($path, "Name,Predecessor\nBuild,Design\n");

        $this->actingAs($this->admin)
            ->post(route('projects.task-lists.analyze', $this->project), [
                'file' => new \Illuminate\Http\UploadedFile($path, 'plan.csv', 'text/csv', null, true),
            ])
            ->assertSuccessful()
            ->assertJsonPath('suggested_mapping.predecessor', 'Predecessor');
    });
});

it('links tasks extracted from text by the predecessor the AI read from context', function () {
    Document::create([
        'project_id' => $this->project->id, 'name' => 'Kickoff', 'type' => 'task',
        'content' => 'x', 'priority' => 'low', 'task_status' => 'todo', 'due_at' => '2026-08-31',
    ]);
    $import = Document::create([
        'project_id' => $this->project->id, 'name' => 'notes.txt', 'type' => 'task_list_import',
        'content' => '', 'processed_at' => now(),
    ]);

    $record = fn (string $name, ?string $start, ?string $due, ?string $predecessor) => [
        'name' => $name, 'priority' => null, 'task_status' => null, 'due_at' => $due,
        'assignee' => null, 'start_date' => $start, 'description' => null, 'tag' => null,
        'predecessor' => $predecessor,
    ];

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn ($system, $user) => str_contains($user, 'Predecessor:') && str_contains($user, '"Kickoff"'))
        ->andReturn(['status' => 'success', 'content' => ['records' => [
            $record('Design', '2026-09-01', '2026-09-10', 'Kickoff'),
            $record('Build', null, '2026-09-14', 'Design'),
        ]]]);

    ExtractTextRecords::dispatchSync($import, 'task', 'source text', 'every bullet is a task');

    expect(($this->task)('Design')->predecessor_id)->not->toBeNull()
        ->and(($this->task)('Build')->predecessor_id)->toBe(($this->task)('Design')->id)
        // Design moves from 09/01 to Kickoff's end (08/31), keeping its length, so its due date
        // moves a day earlier too — and Build starts then.
        ->and(($this->start)('Design'))->toBe('2026-08-31')
        ->and(substr((string) ($this->task)('Design')->due_at, 0, 10))->toBe('2026-09-09')
        ->and(($this->start)('Build'))->toBe('2026-09-09');
});

it('links tasks generated from notes by the predecessor the AI read from context', function () {
    $notes = Document::create([
        'project_id' => $this->project->id, 'name' => 'Notes', 'type' => 'meeting_notes',
        'content' => 'notes', 'processed_at' => now(),
    ]);

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'task',
            'single_output' => false,
            'mock_response' => [
                ['title' => 'Ship', 'task' => 'Ship it', 'due_date' => '2026-09-20', 'predecessor' => 'QA'],
                ['title' => 'QA', 'task' => 'Test it', 'due_date' => '2026-09-15', 'predecessor' => null],
            ],
        ]);
    });

    (new ProcessDocumentAI($notes))->handle();

    expect(($this->task)('Ship')->predecessor_id)->toBe(($this->task)('QA')->id)
        ->and(($this->start)('Ship'))->toBe('2026-09-15');
});

it('links a Slack /task to an existing task named in the command', function () {
    $design = Document::create([
        'project_id' => $this->project->id, 'name' => 'Design', 'type' => 'task',
        'content' => 'x', 'priority' => 'low', 'task_status' => 'todo', 'due_at' => '2026-09-10',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn ($system, $user) => str_contains($user, '"Design"'))
        ->andReturn(['status' => 'success', 'content' => ['records' => [[
            'name' => 'Build', 'priority' => null, 'task_status' => null, 'due_at' => '2026-09-14',
            'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null,
            'predecessor' => 'design',
        ]]]]);

    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true], 200)]);

    CreateTaskFromSlackCommand::dispatchSync($this->project, $this->admin, 'build it after design', 'https://hooks.slack.com/commands/fake');

    expect(($this->task)('Build')->predecessor_id)->toBe($design->id)
        ->and(($this->start)('Build'))->toBe('2026-09-10');
});
