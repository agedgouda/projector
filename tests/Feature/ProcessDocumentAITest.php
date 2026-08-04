<?php

use App\Contracts\LlmDriver;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
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
