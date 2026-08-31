<?php

use App\Contracts\LlmDriver;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\Ai\ProjectAiService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createReprocessableDocument(): Document
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
    ]);

    return Document::create([
        'project_id' => $project->id,
        'name' => 'Source Document',
        'type' => 'intake',
        'content' => 'Source content',
        'processed_at' => now(),
    ]);
}

it('applies the universal intake -> action_items step when no override is given, and locks nothing', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to Action items',
        'type' => 'workflow',
        'system_prompt' => 'Extract action items.',
        'user_prompt' => '{{input}}',
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', config('workflow.action_items_key') => 'Follow up', 'criteria' => []],
            ],
        ]);

    $result = app(ProjectAiService::class)->process($document);

    expect($result['output_type'])->toBe(config('workflow.action_items_key'));
    expect($result['locked_project_type_id'])->toBeNull();
});

it('deletes all previously generated children before creating new ones, even when the output type has changed', function () {
    $document = createReprocessableDocument();

    $oldChild = Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $document->id,
        'name' => 'Old Child',
        'type' => 'old_output_type',
        'content' => 'Old content',
    ]);

    Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $oldChild->id,
        'name' => 'Old Grandchild',
        'type' => 'old_output_type_detail',
        'content' => 'Old grandchild content',
    ]);

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'new_output_type',
            'single_output' => false,
            'mock_response' => [
                [
                    'title' => 'New Deliverable',
                    'new_output_type' => 'New content',
                ],
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    expect(Document::where('type', 'old_output_type')->exists())->toBeFalse();
    expect(Document::where('type', 'old_output_type_detail')->exists())->toBeFalse();

    $newChildren = Document::where('parent_id', $document->id)->get();
    expect($newChildren)->toHaveCount(1);
    expect($newChildren->first())
        ->type->toBe('new_output_type')
        ->name->toBe('New Deliverable');

    expect($document->refresh()->processed_at)->not->toBeNull();
});

it('reuses a single existing child in place for a single-output run, instead of deleting and recreating it', function () {
    $document = createReprocessableDocument();

    $existingChild = Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $document->id,
        'name' => 'Old Meeting Notes',
        'type' => 'action_items',
        'content' => 'Old content',
    ]);

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'action_items',
            'single_output' => true,
            'mock_response' => ['title' => 'New Meeting Notes', 'content' => 'New content'],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $children = Document::where('parent_id', $document->id)->get();
    expect($children)->toHaveCount(1);
    expect($children->first()->id)->toBe($existingChild->id);
    expect($children->first())
        ->name->toBe('New Meeting Notes')
        ->content->toContain('New content');
});

it('falls back to deleting and recreating for a single-output run when more than one child already exists', function () {
    $document = createReprocessableDocument();

    Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $document->id,
        'name' => 'Old Item A',
        'type' => 'action_items',
        'content' => 'A',
    ]);
    Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $document->id,
        'name' => 'Old Item B',
        'type' => 'action_items',
        'content' => 'B',
    ]);

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'action_items',
            'single_output' => true,
            'mock_response' => ['title' => 'Consolidated Notes', 'content' => 'New content'],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $children = Document::where('parent_id', $document->id)->get();
    expect($children)->toHaveCount(1);
    expect($children->first())
        ->name->toBe('Consolidated Notes')
        ->content->toContain('New content');
    expect(Document::where('name', 'Old Item A')->exists())->toBeFalse();
    expect(Document::where('name', 'Old Item B')->exists())->toBeFalse();
});

it('persists the priority the AI assigns to each generated task', function () {
    $document = createReprocessableDocument();

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'task',
            'single_output' => false,
            'mock_response' => [
                ['title' => 'Urgent Fix', 'task' => 'Fix the bug', 'priority' => 'high'],
                ['title' => 'Routine Cleanup', 'task' => 'Tidy up', 'priority' => 'low'],
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $children = Document::where('parent_id', $document->id)->orderBy('name')->get();
    expect($children->firstWhere('name', 'Urgent Fix')->priority)->toBe('high');
    expect($children->firstWhere('name', 'Routine Cleanup')->priority)->toBe('low');
});

it('defaults priority to medium when the AI omits it or returns an unrecognized value', function (mixed $priority) {
    $document = createReprocessableDocument();

    $this->mock(ProjectAiService::class, function ($mock) use ($priority) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'task',
            'single_output' => false,
            'mock_response' => [
                array_filter([
                    'title' => 'Some Task',
                    'task' => 'Do it',
                    'priority' => $priority,
                ], fn ($value) => $value !== null),
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    expect(Document::where('parent_id', $document->id)->firstOrFail()->priority)->toBe('medium');
})->with([
    'omitted' => [null],
    'invalid string' => ['urgent'],
]);

it('persists the start_date the AI assigns to a generated document as start_at, alongside due_date as due_at', function () {
    $document = createReprocessableDocument();

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'event',
            'single_output' => false,
            'mock_response' => [
                [
                    'title' => 'Kickoff Meeting',
                    'event' => 'Project kickoff',
                    'start_date' => '2026-03-10',
                    'due_date' => '2026-03-12',
                ],
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->start_at)->toStartWith('2026-03-10')
        ->and($child->due_at)->toStartWith('2026-03-12');
});

it('leaves start_at null when the AI omits start_date', function () {
    $document = createReprocessableDocument();

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'task',
            'single_output' => false,
            'mock_response' => [
                ['title' => 'Some Task', 'task' => 'Do it'],
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    expect(Document::where('parent_id', $document->id)->firstOrFail()->start_at)->toBeNull();
});

it('converts a markdown table in single-output content to an HTML table', function () {
    $document = createReprocessableDocument();

    $markdown = "# Title\n\n| Field | Value |\n|---|---|\n| Name | Acme |\n";

    $this->mock(ProjectAiService::class, function ($mock) use ($markdown) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'software_sow',
            'single_output' => true,
            'mock_response' => ['title' => 'SOW', 'content' => $markdown],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->content)->toContain('<table>')
        ->toContain('<th>Field</th>')
        ->toContain('<td>Acme</td>')
        ->not->toContain('|---|---|');
});

it('instructs the model to respond in English only for single-document transformations', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => '{{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt) => str_contains($systemPrompt, 'Respond only in English'))
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'SOW', 'content' => 'Body'],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ]);
});

it('appends one-off instructions passed to process() after the template\'s own system_prompt, for single-document transformations', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => '{{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(function (string $systemPrompt) {
            $basePos = strpos($systemPrompt, 'Write a SOW.');
            $oneOffPos = strpos($systemPrompt, 'only extract from the Action Items section');

            return $basePos !== false && $oneOffPos !== false && $basePos < $oneOffPos;
        })
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'SOW', 'content' => 'Body'],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ], 'For this run, only extract from the Action Items section.');
});

it('appends one-off instructions after the template\'s own system_prompt, for multi-item transformations', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to Tasks',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(function (string $systemPrompt) {
            $basePos = strpos($systemPrompt, 'Extract tasks.');
            $oneOffPos = strpos($systemPrompt, 'only extract from the Action Items section');

            return $basePos !== false && $oneOffPos !== false && $basePos < $oneOffPos;
        })
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'Item', 'action_items' => 'Follow up', 'criteria' => []]],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'action_items',
        'ai_template_id' => $template->id,
    ], 'For this run, only extract from the Action Items section.');
});

it('does not append anything to the system prompt when no one-off instructions are given', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => '{{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt) => trim(str_replace('Respond only in English. Do not use any non-English words, phrases, or characters, including in headings, labels, or examples.', '', $systemPrompt)) === 'Write a SOW.')
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'SOW', 'content' => 'Body'],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ]);
});

it('instructs the model to respond in English only for multi-item transformations', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to Tasks',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt) => str_contains($systemPrompt, 'Respond only in English'))
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'Task', 'task' => 'Do the thing', 'criteria' => []]],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'task',
        'ai_template_id' => $template->id,
    ]);
});

function createActionItemsDocumentWithTemplates(): array
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $templateA = AiTemplate::create([
        'name' => 'Action Items to Task',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);
    $templateB = AiTemplate::create([
        'name' => 'Action Items to Follow-up',
        'type' => 'workflow',
        'system_prompt' => 'Extract follow-ups.',
        'user_prompt' => '{{input}}',
    ]);

    $projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
            ['label' => 'Follow-up', 'key' => 'followup', 'is_task' => false],
        ],
        'workflow' => [
            ['from_key' => 'action_items', 'to_key' => 'task', 'ai_template_id' => $templateA->id],
        ],
    ]);

    $project = Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
    ]);

    $document = Document::create([
        'project_id' => $project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up with the client',
        'processed_at' => now(),
    ]);

    return [$document, $templateA, $templateB, $projectType];
}

it('runs an explicit override step instead of the project type workflow, for any target type/template', function () {
    [$document, , $templateB] = createActionItemsDocumentWithTemplates();

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', 'followup' => 'Send a note', 'criteria' => []],
            ],
        ]);

    $result = app(ProjectAiService::class)->process($document, [
        'to_key' => 'followup',
        'ai_template_id' => $templateB->id,
    ]);

    expect($result['output_type'])->toBe('followup');
});

it('substitutes {{client_name}} and {{vendor_name}} from the project\'s client and its organization', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => 'Client: {{client_name}}. Vendor: {{vendor_name}}. Notes: {{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(function (string $systemPrompt, string $userPrompt) {
            return str_contains($userPrompt, 'Client: Client Co.')
                && str_contains($userPrompt, 'Vendor: Acme Inc.');
        })
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'SOW', 'content' => 'Body'],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ]);
});

it('falls back to "TBD" for {{vendor_name}} when the client has no organization', function () {
    $client = Client::create([
        'company_name' => 'Orgless Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create([
        'name' => 'Orgless Client Project',
        'client_id' => $client->id,
    ]);
    $document = Document::create([
        'project_id' => $project->id,
        'name' => 'Source Document',
        'type' => 'intake',
        'content' => 'Source content',
        'processed_at' => now(),
    ]);

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => 'Client: {{client_name}}. Vendor: {{vendor_name}}. Notes: {{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(function (string $systemPrompt, string $userPrompt) {
            return str_contains($userPrompt, 'Client: Orgless Client.')
                && str_contains($userPrompt, 'Vendor: TBD.');
        })
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'SOW', 'content' => 'Body'],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ]);
});

it('uses the single-document path for a direct override when the template itself is marked single_output, even though the override omits it', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => '{{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'Statement of Work', 'content' => "# Scope\n\n- Item one"],
        ]);

    $result = app(ProjectAiService::class)->process($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ]);

    expect($result['single_output'])->toBeTrue();
    expect($result['mock_response']['content'])->toBe("# Scope\n\n- Item one");
});

it('records the ai template and output key that produced a document\'s children', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', 'task' => 'Do it', 'criteria' => []],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $document->refresh();
    expect($document->last_ai_template_id)->toBe($templateA->id);
    expect($document->last_output_key)->toBe('task');
});

it('clears last_ai_template_id when a run produces no ai_template_id (e.g. a custom-prompt run)', function () {
    $document = createReprocessableDocument();
    $document->update(['last_ai_template_id' => AiTemplate::create([
        'name' => 'Stale Template',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
    ])->id, 'last_output_key' => 'stale_key']);

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'action_items',
            'single_output' => true,
            'mock_response' => ['title' => 'Cleaned Notes', 'content' => 'Body'],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $document->refresh();
    expect($document->last_ai_template_id)->toBeNull();
    expect($document->last_output_key)->toBeNull();
});

it('returns null when no override is given and the document is not locked to a protocol', function () {
    [$document] = createActionItemsDocumentWithTemplates();

    $result = app(ProjectAiService::class)->process($document);

    expect($result)->toBeNull();
});

it('uses the locked protocol\'s own workflow step when no override is given, and propagates the lock', function () {
    [$document, $templateA, , $projectType] = createActionItemsDocumentWithTemplates();

    WorkflowStep::create([
        'project_type_id' => $projectType->id,
        'from_key' => 'action_items',
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
        'order' => 1,
    ]);
    $document->update(['locked_project_type_id' => $projectType->id]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', 'task' => 'Do it', 'criteria' => []],
            ],
        ]);

    $result = app(ProjectAiService::class)->process($document);

    expect($result['output_type'])->toBe('task');
    expect($result['locked_project_type_id'])->toBe($projectType->id);
});

it('resolves an @-mentioned assignee from the source document into the generated task\'s assignee_id', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $user = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $document->update([
        'content' => 'Follow up with the client — <span class="mention" data-id="'.$user->id.'" data-label="Jane Doe">Jane Doe</span> will own this.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => str_contains($userMessage, '"Jane Doe"') && str_contains($userMessage, 'assignee_name'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Follow up', 'task' => 'Send a note', 'criteria' => [], 'assignee_name' => 'Jane Doe'],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->assignee_id)->toBe($user->id);
});

it('leaves assignee_id null when the AI names someone who was never actually @-mentioned', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Follow up', 'task' => 'Send a note', 'criteria' => [], 'assignee_name' => 'Jane Doe'],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->assignee_id)->toBeNull();
});

it('assigns to whichever of multiple @-mentioned people the AI names for that task', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $john = User::factory()->create(['first_name' => 'John', 'last_name' => 'Smith']);

    $document->update([
        'content' => '<span class="mention" data-id="'.$jane->id.'" data-label="Jane Doe">Jane Doe</span> and '.
            '<span class="mention" data-id="'.$john->id.'" data-label="John Smith">John Smith</span> need to follow up.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Task A', 'task' => 'Do A', 'criteria' => [], 'assignee_name' => 'John Smith'],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->assignee_id)->toBe($john->id);
});

it('resolves an @-mentioned pending invitee into pending_assignee_invitation_id, not assignee_id', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $invitation = OrganizationInvitation::create([
        'organization_id' => $document->project->client->organization_id,
        'email' => 'invited@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'token' => str_repeat('c', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $document->update([
        'content' => 'Follow up with the client — <span class="mention" data-id="inv:'.$invitation->id.'" data-label="Jane Doe">Jane Doe</span> will own this.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => str_contains($userMessage, '"Jane Doe"') && str_contains($userMessage, 'assignee_name'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Follow up', 'task' => 'Send a note', 'criteria' => [], 'assignee_name' => 'Jane Doe'],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->assignee_id)->toBeNull()
        ->and($child->pending_assignee_invitation_id)->toBe($invitation->id);
});

it('does not ask for an assignee when the source document has no @-mentions', function () {
    $document = createReprocessableDocument();

    $template = AiTemplate::create([
        'name' => 'Notes to Tasks',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => ! str_contains($userMessage, 'assignee_name'))
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'Task', 'task' => 'Do it', 'criteria' => []]],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'task',
        'ai_template_id' => $template->id,
    ]);
});

it('carries an uploaded image straight through a single-output transformation, with no detection prompt', function () {
    $document = createReprocessableDocument();
    $document->update([
        'content' => 'Notes <img src="https://example.test/storage/content-uploads/proj/abc.png" alt="diagram.png"> more notes.',
    ]);

    $template = AiTemplate::create([
        'name' => 'Notes to SOW',
        'type' => 'workflow',
        'system_prompt' => 'Write a SOW.',
        'user_prompt' => '{{input}}',
        'single_output' => true,
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt) => ! str_contains($systemPrompt, 'image_ids'))
        ->andReturn([
            'status' => 'success',
            'content' => ['title' => 'SOW', 'content' => 'Body text'],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'software_sow',
        'ai_template_id' => $template->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->content)->toContain('<img src="https://example.test/storage/content-uploads/proj/abc.png" alt="diagram.png">');
});

it('detects which task an uploaded image belongs with and carries it into that task only', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $document->update([
        'content' => 'Follow up with the client <img src="https://example.test/storage/content-uploads/proj/abc.png" alt="budget-screenshot.png"> about the budget.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => str_contains($userMessage, 'budget-screenshot.png') && str_contains($userMessage, 'image_ids'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Follow up', 'task' => 'Send budget note', 'criteria' => [], 'image_ids' => [1]],
                ['title' => 'Other task', 'task' => 'Do something else', 'criteria' => []],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $children = Document::where('parent_id', $document->id)->get();
    $claimed = $children->firstWhere('name', 'Follow up');
    $unclaimed = $children->firstWhere('name', 'Other task');

    expect($claimed->content)->toContain('<img src="https://example.test/storage/content-uploads/proj/abc.png" alt="budget-screenshot.png">');
    expect($unclaimed->content)->not->toContain('<img');
});

it('describes each image by the text it actually appeared next to, not just its filename', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    // Realistic shape: images live inside the same block (li > p) as the text they relate to,
    // with generic auto-generated screenshot filenames carrying no real signal on their own.
    $document->update([
        'content' => '<ol>'
            .'<li><p>Send Linz availability for the regroup meeting.<img src="https://example.test/one.png" alt="Screenshot 2026-08-06 at 11.21.56 AM.png"></p></li>'
            .'<li><p>Review the Q3 cost report before the call.<img src="https://example.test/two.png" alt="Screenshot 2026-07-07 at 1.51.38 PM.png"></p></li>'
            .'</ol>',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => str_contains($userMessage, 'Send Linz availability for the regroup meeting')
            && str_contains($userMessage, 'Review the Q3 cost report before the call'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Send Linz availability', 'task' => 'Share times with Linz', 'criteria' => [], 'image_ids' => [1]],
                ['title' => 'Review cost report', 'task' => 'Go over Q3 costs', 'criteria' => [], 'image_ids' => [2]],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $children = Document::where('parent_id', $document->id)->get();
    $linz = $children->firstWhere('name', 'Send Linz availability');
    $costs = $children->firstWhere('name', 'Review cost report');

    expect($linz->content)->toContain('one.png')->not->toContain('two.png');
    expect($costs->content)->toContain('two.png')->not->toContain('one.png');
});

it('does not attach an image to any task when the AI omits image_ids entirely', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $document->update([
        'content' => 'Notes <img src="https://example.test/storage/content-uploads/proj/abc.png" alt="diagram.png"> more notes.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Task', 'task' => 'Do it', 'criteria' => []],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->content)->not->toContain('<img');
});

it('does not attach an image to any task when the AI returns an image id that was never offered', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $document->update([
        'content' => 'Notes <img src="https://example.test/storage/content-uploads/proj/abc.png" alt="diagram.png"> more notes.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Task', 'task' => 'Do it', 'criteria' => [], 'image_ids' => [99]],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->content)->not->toContain('<img');
});

it('distributes multiple uploaded images across the tasks that claim them', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $document->update([
        'content' => 'Notes <img src="https://example.test/one.png" alt="one.png"> and '.
            '<img src="https://example.test/two.png" alt="two.png"> more notes.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Task A', 'task' => 'Do A', 'criteria' => [], 'image_ids' => [1]],
                ['title' => 'Task B', 'task' => 'Do B', 'criteria' => [], 'image_ids' => [2]],
                ['title' => 'Task C', 'task' => 'Do C', 'criteria' => []],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $children = Document::where('parent_id', $document->id)->get();
    $a = $children->firstWhere('name', 'Task A');
    $b = $children->firstWhere('name', 'Task B');
    $c = $children->firstWhere('name', 'Task C');

    expect($a->content)->toContain('one.png')->not->toContain('two.png');
    expect($b->content)->toContain('two.png')->not->toContain('one.png');
    expect($c->content)->not->toContain('<img');
});

it('does not ask about images when the source document has no uploaded images', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => ! str_contains($userMessage, 'image_ids') && ! str_contains($systemPrompt, 'image_ids'))
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'Task', 'task' => 'Do it', 'criteria' => []]],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();
});

it('escapes special characters in an uploaded image\'s filename when carrying it into a task', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    $document->update([
        'content' => 'Notes <img src="https://example.test/storage/content-uploads/proj/abc.png" alt="quote&quot;file.png"> more notes.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Task', 'task' => 'Do it', 'criteria' => [], 'image_ids' => [1]],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->content)->toContain('alt="quote&quot;file.png"')
        ->not->toContain('alt="quote"file.png"');
});

// Whether/how to ask for tags is the transformation's own call, authored into its own
// prompt text (like "Create Tasks" — see the 2026_08_26_120000 migration) — the app only
// supplies the real per-project data via {{available_tags}} and reacts to whatever the
// template's own composed prompt ends up asking for. It never injects a tag_names request
// on its own, unlike a plain template that never mentions tags at all.
it('substitutes {{available_tags}} with the project\'s exact tag names, for a template that asks for tag_names', function () {
    [$document] = createActionItemsDocumentWithTemplates();
    Category::create(['project_id' => $document->project_id, 'name' => 'Design', 'color' => 'pink']);
    Category::create(['project_id' => $document->project_id, 'name' => 'Backend', 'color' => 'blue']);

    $template = AiTemplate::create([
        'name' => 'Notes to Tagged Tasks',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}} Also include "tag_names" from: {{available_tags}}.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => str_contains($userMessage, 'tag_names')
            && str_contains($userMessage, '"Design"')
            && str_contains($userMessage, '"Backend"'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', 'task' => 'Do it', 'criteria' => []],
            ],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'task',
        'ai_template_id' => $template->id,
    ]);
});

it('does not ask about tags when the template\'s own prompt never mentions tag_names, even though the project has tags', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();
    Category::create(['project_id' => $document->project_id, 'name' => 'Design', 'color' => 'pink']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => ! str_contains($userMessage, 'tag_names') && ! str_contains($systemPrompt, 'tag_names'))
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'Task', 'task' => 'Do it', 'criteria' => []]],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]);
});

it('substitutes a friendly fallback for {{available_tags}} when the project has no tags', function () {
    [$document] = createActionItemsDocumentWithTemplates();

    $template = AiTemplate::create([
        'name' => 'Notes to Tagged Tasks',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}} Also include "tag_names" from: {{available_tags}}.',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => str_contains($userMessage, 'no tags exist for this project'))
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'Task', 'task' => 'Do it', 'criteria' => []]],
        ]);

    app(ProjectAiService::class)->process($document, [
        'to_key' => 'task',
        'ai_template_id' => $template->id,
    ]);
});

it('resolves an AI-picked tag name into the generated document\'s tags', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();
    $design = Category::create(['project_id' => $document->project_id, 'name' => 'Design', 'color' => 'pink']);
    Category::create(['project_id' => $document->project_id, 'name' => 'Backend', 'color' => 'blue']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Redesign homepage', 'task' => 'Do it', 'criteria' => [], 'tag_names' => ['Design']],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->categories()->pluck('categories.id')->all())->toBe([$design->id]);
});

it('ignores an AI-picked tag name that was never actually offered', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();
    Category::create(['project_id' => $document->project_id, 'name' => 'Design', 'color' => 'pink']);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', 'task' => 'Do it', 'criteria' => [], 'tag_names' => ['Nonexistent Tag']],
            ],
        ]);

    (new ProcessDocumentAI($document, [
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
    ]))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->categories()->count())->toBe(0);
});

it('the "Create Tasks" tag-selection migration adds tag_names to the template\'s own prompt, reversibly', function () {
    $template = AiTemplate::create([
        'name' => 'Create Tasks',
        'type' => 'workflow',
        'system_prompt' => '<li><p>Do not extract assignee or owner information.</p></li>',
        'user_prompt' => "due_date: An ISO 8601 date string (YYYY-MM-DD) resolved from any deadline or delivery language in the item, or null if none.\n\nStrategic Instructions:\n\nKeys: You MUST use the exact keys \"title\", \"{{output_key}}\", \"criteria\", \"priority\", and \"due_date\".\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"{{output_key}}\", \"criteria\", \"priority\", and \"due_date\".",
    ]);

    $migration = require database_path('migrations/2026_08_26_120000_add_tag_selection_to_create_tasks_template_prompt.php');

    $migration->up();
    $template->refresh();
    expect($template->system_prompt)->toContain('Tags:')->toContain('{{available_tags}}')
        ->and($template->user_prompt)->toContain('tag_names')->toContain('{{available_tags}}');

    $migration->down();
    expect($template->fresh()->system_prompt)->not->toContain('Tags:')
        ->and($template->fresh()->user_prompt)->not->toContain('tag_names');
});

it('the "Notes to Events" tag-selection migration adds tag_names to the template\'s own prompt, reversibly', function () {
    // Unlike "Create Tasks" (production-only data), "Notes to Events" is seeded by an earlier
    // migration that runs in every environment including tests — already carries this
    // migration's tags by the time the test suite boots, so this rolls it back and forward
    // on the one real row instead of creating a same-named duplicate the where('name', ...)
    // lookup inside the migration could pick up instead.
    $template = AiTemplate::where('name', 'Notes to Events')->firstOrFail();

    $migration = require database_path('migrations/2026_08_26_130000_add_tag_selection_to_notes_to_events_template_prompt.php');

    $migration->down();
    $template->refresh();
    expect($template->system_prompt)->not->toContain('Tags:')
        ->and($template->user_prompt)->not->toContain('tag_names');

    $migration->up();
    $template->refresh();
    expect($template->system_prompt)->toContain('Tags:')->toContain('{{available_tags}}')
        ->and($template->user_prompt)->toContain('tag_names')->toContain('{{available_tags}}')
        ->and($template->user_prompt)->toContain('at most one');
});

it('caps a generated event\'s tags to a single one, even if the AI names more than one', function () {
    [$document] = createActionItemsDocumentWithTemplates();
    $design = Category::create(['project_id' => $document->project_id, 'name' => 'Design', 'color' => 'pink']);
    $backend = Category::create(['project_id' => $document->project_id, 'name' => 'Backend', 'color' => 'blue']);

    $this->mock(ProjectAiService::class, function ($mock) use ($design, $backend) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'event',
            'single_output' => false,
            'mock_response' => [
                ['title' => 'Kickoff', 'event' => 'Kickoff event', '_category_ids' => [$design->id, $backend->id]],
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->categories()->count())->toBe(1)
        ->and($child->categories()->pluck('categories.id')->all())->toBe([$design->id]);
});
