<?php

use App\Contracts\LlmDriver;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
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
    $projectType = ProjectType::factory()->create();
    $project = Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
        'project_type_id' => $projectType->id,
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
        'project_type_id' => $projectType->id,
    ]);

    $document = Document::create([
        'project_id' => $project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up with the client',
        'processed_at' => now(),
    ]);

    return [$document, $templateA, $templateB];
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

it('returns null when no override is given and the document is not locked to a protocol', function () {
    [$document] = createActionItemsDocumentWithTemplates();

    $result = app(ProjectAiService::class)->process($document);

    expect($result)->toBeNull();
});

it('uses the locked protocol\'s own workflow step when no override is given, and propagates the lock', function () {
    [$document, $templateA] = createActionItemsDocumentWithTemplates();

    WorkflowStep::create([
        'project_type_id' => $document->project->project_type_id,
        'from_key' => 'action_items',
        'to_key' => 'task',
        'ai_template_id' => $templateA->id,
        'order' => 1,
    ]);
    $document->update(['locked_project_type_id' => $document->project->project_type_id]);

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
    expect($result['locked_project_type_id'])->toBe($document->project->project_type_id);
});
