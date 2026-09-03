<?php

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
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
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

/**
 * The classifier's response shape: one pass per detected record type.
 */
function classificationContent(): array
{
    return [
        'passes' => [
            [
                'list_type' => 'event',
                'mapping' => [
                    'name' => 'Name', 'priority' => null, 'task_status' => null,
                    'due_at' => 'End Date', 'assignee' => null, 'start_date' => 'Start Date',
                    'description' => null, 'tag' => 'Category',
                ],
                'rationale' => 'Name/Category/Start/End describe an event per row.',
            ],
            [
                'list_type' => 'task',
                'mapping' => [
                    'name' => 'Assets Needed', 'priority' => null, 'task_status' => null,
                    'due_at' => 'End Date', 'assignee' => null, 'start_date' => null,
                    'description' => null, 'tag' => null,
                ],
                'rationale' => 'Assets Needed lists concrete deliverables, not always present.',
            ],
        ],
    ];
}

it('classifies an uploaded sheet into one pass per detected record type', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => classificationContent()]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.classify', $this->project), [
            'headers' => ['Name', 'Category', 'Start Date', 'End Date', 'Assets Needed'],
            'rows' => [
                ['AFA Conference', 'Partner', '9/16/2026', '9/16/2026', 'Image Release'],
            ],
        ])
        ->assertOk();

    $response->assertJsonPath('passes.0.list_type', 'event');
    $response->assertJsonPath('passes.1.list_type', 'task');
    $response->assertJsonPath('passes.1.mapping.name', 'Assets Needed');
});

it('passes the header row and sample rows into the classification prompt', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userPrompt) => str_contains($userPrompt, 'Name, Category, Start Date, End Date, Assets Needed')
            && str_contains($userPrompt, 'AFA Conference'))
        ->andReturn(['status' => 'success', 'content' => classificationContent()]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.classify', $this->project), [
            'headers' => ['Name', 'Category', 'Start Date', 'End Date', 'Assets Needed'],
            'rows' => [
                ['AFA Conference', 'Partner', '9/16/2026', '9/16/2026', 'Image Release'],
            ],
        ])
        ->assertOk();
});

it('surfaces a classification failure as an error rather than a partial response', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'Upstream failure']);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.classify', $this->project), [
            'headers' => ['Name'],
            'rows' => [['Anything']],
        ])
        ->assertServerError();
});

it('creates fully separate task and event documents from the same sheet via apply', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply', $this->project), [
            'original_filename' => 'marketing.csv',
            'headers' => ['Name', 'Category', 'Start Date', 'End Date', 'Assets Needed'],
            'rows' => [
                ['AFA Conference', 'Partner', '2026-09-16', '2026-09-16', 'Image Release'],
                ['AFA Email Blast', 'Partner', '2026-11-03', '2026-11-03', ''],
            ],
            'passes' => [
                [
                    'list_type' => 'event',
                    'mapping' => ['name' => 'Name', 'start_date' => 'Start Date', 'due_at' => 'End Date', 'tag' => 'Category'],
                ],
                [
                    'list_type' => 'task',
                    'mapping' => ['name' => 'Assets Needed', 'due_at' => 'End Date'],
                ],
            ],
        ])
        ->assertOk();

    $response->assertJsonPath('total', 2);
    $response->assertJsonCount(2, 'passes');

    $events = Document::where('type', 'event')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('name')->all())->toContain('AFA Conference', 'AFA Email Blast');

    // Only the row with content in "Assets Needed" produces a task — the blank second row is
    // skipped by the same "no name, no record" rule a single-type import already has.
    $tasks = Document::where('type', 'task')->get();
    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->name)->toBe('Image Release');

    // No DB relationship between the two — each is its own independent document, tied only to
    // the project, never to each other.
    expect($tasks->first()->parent_id)->toBeNull();
});

it('stamps last_ai_template_id and last_output_key when applying a saved transformation', function () {
    $template = AiTemplate::create([
        'name' => 'Marketing Calendar Sheet',
        'type' => 'spreadsheet_import',
        'organization_id' => $this->org->id,
        'import_config' => ['passes' => [
            ['list_type' => 'event', 'mapping' => ['name' => 'Name', 'due_at' => 'End Date']],
        ]],
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply', $this->project), [
            'headers' => ['Name', 'End Date'],
            'rows' => [['Kickoff', '2026-09-01']],
            'ai_template_id' => $template->id,
            'passes' => [
                ['list_type' => 'event', 'mapping' => ['name' => 'Name', 'due_at' => 'End Date']],
            ],
        ])
        ->assertOk();

    $event = Document::where('type', 'event')->where('name', 'Kickoff')->firstOrFail();
    expect($event->last_ai_template_id)->toBe($template->id)
        ->and($event->last_output_key)->toBe('event');
});

it('leaves provenance null for an ad-hoc apply with no saved transformation', function () {
    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply', $this->project), [
            'headers' => ['Name', 'End Date'],
            'rows' => [['Kickoff', '2026-09-01']],
            'passes' => [
                ['list_type' => 'event', 'mapping' => ['name' => 'Name', 'due_at' => 'End Date']],
            ],
        ])
        ->assertOk();

    $event = Document::where('type', 'event')->where('name', 'Kickoff')->firstOrFail();
    expect($event->last_ai_template_id)->toBeNull()
        ->and($event->last_output_key)->toBeNull();
});

it('rejects an ai_template_id belonging to a different organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherTemplate = AiTemplate::create([
        'name' => 'Other Org Transformation',
        'type' => 'spreadsheet_import',
        'organization_id' => $otherOrg->id,
        'import_config' => ['passes' => [
            ['list_type' => 'event', 'mapping' => ['name' => 'Name']],
        ]],
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply', $this->project), [
            'headers' => ['Name'],
            'rows' => [['Kickoff']],
            'ai_template_id' => $otherTemplate->id,
            'passes' => [
                ['list_type' => 'event', 'mapping' => ['name' => 'Name']],
            ],
        ])
        ->assertJsonValidationErrors('ai_template_id');
});

it('lists saved spreadsheet-import transformations for the current org plus global ones', function () {
    AiTemplate::create([
        'name' => 'Org Transformation',
        'type' => 'spreadsheet_import',
        'organization_id' => $this->org->id,
        'import_config' => ['passes' => []],
    ]);
    AiTemplate::create([
        'name' => 'Global Transformation',
        'type' => 'spreadsheet_import',
        'organization_id' => null,
        'import_config' => ['passes' => []],
    ]);
    $otherOrg = Organization::create(['name' => 'Other Org']);
    AiTemplate::create([
        'name' => 'Other Org Transformation',
        'type' => 'spreadsheet_import',
        'organization_id' => $otherOrg->id,
        'import_config' => ['passes' => []],
    ]);
    // A normal workflow-type template should never show up in this listing.
    AiTemplate::create([
        'name' => 'Unrelated Workflow Template',
        'type' => 'workflow',
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('import-transformations.index'))
        ->assertOk();

    $names = collect($response->json('transformations'))->pluck('name');
    expect($names)->toContain('Org Transformation')
        ->toContain('Global Transformation')
        ->not->toContain('Other Org Transformation')
        ->not->toContain('Unrelated Workflow Template');
});
