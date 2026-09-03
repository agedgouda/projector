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

function textClassificationContent(): array
{
    return [
        'passes' => [
            [
                'list_type' => 'event',
                'extraction_rule' => 'Each meeting mentioned with a date is an event.',
                'rationale' => 'The notes describe two dated meetings.',
            ],
            [
                'list_type' => 'task',
                'extraction_rule' => 'Each bolded action item under "Follow-ups" is a task.',
                'rationale' => 'A follow-ups section lists discrete deliverables.',
            ],
        ],
    ];
}

function textExtractionRecord(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kickoff Meeting',
        'priority' => null,
        'task_status' => null,
        'due_at' => '2026-09-01',
        'assignee' => null,
        'start_date' => '2026-09-01',
        'description' => null,
        'tag' => null,
    ], $overrides);
}

it('classifies source text into one pass per detected record type', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => textClassificationContent()]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.classify-text', $this->project), [
            'text' => 'Kickoff meeting on 9/1. Follow-ups: get trailer.',
        ])
        ->assertOk();

    $response->assertJsonPath('passes.0.list_type', 'event');
    $response->assertJsonPath('passes.1.list_type', 'task');
    $response->assertJsonPath('passes.1.extraction_rule', 'Each bolded action item under "Follow-ups" is a task.');
});

it('passes the source text into the classification prompt', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userPrompt) => str_contains($userPrompt, 'Kickoff meeting on 9/1'))
        ->andReturn(['status' => 'success', 'content' => textClassificationContent()]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.classify-text', $this->project), [
            'text' => 'Kickoff meeting on 9/1. Follow-ups: get trailer.',
        ])
        ->assertOk();
});

it('surfaces a text classification failure as an error rather than a partial response', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'Upstream failure']);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.classify-text', $this->project), [
            'text' => 'Anything',
        ])
        ->assertServerError();
});

it('creates fully separate task and event documents from the same text via applyText', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->twice()
        ->andReturn(
            ['status' => 'success', 'content' => ['records' => [textExtractionRecord(['name' => 'Kickoff Meeting'])]]],
            ['status' => 'success', 'content' => ['records' => [
                textExtractionRecord(['name' => 'Get trailer', 'start_date' => null]),
                textExtractionRecord(['name' => '']), // blank name — must be skipped
            ]]],
        );

    $response = $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply-text', $this->project), [
            'text' => 'Kickoff meeting on 9/1. Follow-ups: get trailer.',
            'passes' => [
                ['list_type' => 'event', 'extraction_rule' => 'Each meeting is an event.'],
                ['list_type' => 'task', 'extraction_rule' => 'Each follow-up is a task.'],
            ],
        ])
        ->assertOk();

    $response->assertJsonCount(2, 'passes');

    $events = Document::where('type', 'event')->get();
    expect($events)->toHaveCount(1)
        ->and($events->first()->name)->toBe('Kickoff Meeting');

    $tasks = Document::where('type', 'task')->get();
    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->name)->toBe('Get trailer');
});

it('stamps last_ai_template_id and last_output_key when applying a saved text transformation', function () {
    $template = AiTemplate::create([
        'name' => 'Meeting Notes Extractor',
        'type' => 'text_import',
        'organization_id' => $this->org->id,
        'import_config' => ['passes' => [
            ['list_type' => 'event', 'extraction_rule' => 'Each meeting is an event.'],
        ]],
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['records' => [textExtractionRecord(['name' => 'Kickoff'])]]]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply-text', $this->project), [
            'text' => 'Kickoff meeting on 9/1.',
            'ai_template_id' => $template->id,
            'passes' => [
                ['list_type' => 'event', 'extraction_rule' => 'Each meeting is an event.'],
            ],
        ])
        ->assertOk();

    $event = Document::where('type', 'event')->where('name', 'Kickoff')->firstOrFail();
    expect($event->last_ai_template_id)->toBe($template->id)
        ->and($event->last_output_key)->toBe('event');
});

it('leaves provenance null for an ad-hoc applyText with no saved transformation', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['records' => [textExtractionRecord(['name' => 'Kickoff'])]]]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply-text', $this->project), [
            'text' => 'Kickoff meeting on 9/1.',
            'passes' => [
                ['list_type' => 'event', 'extraction_rule' => 'Each meeting is an event.'],
            ],
        ])
        ->assertOk();

    $event = Document::where('type', 'event')->where('name', 'Kickoff')->firstOrFail();
    expect($event->last_ai_template_id)->toBeNull()
        ->and($event->last_output_key)->toBeNull();
});

it('rejects a text ai_template_id belonging to a different organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherTemplate = AiTemplate::create([
        'name' => 'Other Org Text Transformation',
        'type' => 'text_import',
        'organization_id' => $otherOrg->id,
        'import_config' => ['passes' => [
            ['list_type' => 'event', 'extraction_rule' => 'Each meeting is an event.'],
        ]],
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply-text', $this->project), [
            'text' => 'Kickoff meeting on 9/1.',
            'ai_template_id' => $otherTemplate->id,
            'passes' => [
                ['list_type' => 'event', 'extraction_rule' => 'Each meeting is an event.'],
            ],
        ])
        ->assertJsonValidationErrors('ai_template_id');
});

it('lists both spreadsheet and text saved transformations for the current org', function () {
    AiTemplate::create([
        'name' => 'Org Spreadsheet Transformation',
        'type' => 'spreadsheet_import',
        'organization_id' => $this->org->id,
        'import_config' => ['passes' => []],
    ]);
    AiTemplate::create([
        'name' => 'Org Text Transformation',
        'type' => 'text_import',
        'organization_id' => $this->org->id,
        'import_config' => ['passes' => []],
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('import-transformations.index'))
        ->assertOk();

    $names = collect($response->json('transformations'))->pluck('name');
    expect($names)->toContain('Org Spreadsheet Transformation')
        ->toContain('Org Text Transformation');
});
