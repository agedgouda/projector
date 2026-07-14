<?php

use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createProjectWithChainedWorkflow(): Project
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
        'workflow' => [
            ['from_key' => 'intake', 'to_key' => 'action_items', 'ai_template_id' => null],
            ['from_key' => 'action_items', 'to_key' => 'task', 'ai_template_id' => null],
        ],
    ]);

    return Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
        'project_type_id' => $projectType->id,
    ]);
}

it('dispatches AI processing for a root document whose type starts a workflow step', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $project = createProjectWithChainedWorkflow();

    $note = $project->documents()->create([
        'name' => 'Meeting Notes',
        'type' => 'intake',
        'content' => 'Some notes',
    ]);

    Queue::assertPushed(ProcessDocumentAI::class, fn ($job) => $job->document->is($note));
});

it('does not cascade AI processing to a workflow-generated child document', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $project = createProjectWithChainedWorkflow();

    $note = $project->documents()->create([
        'name' => 'Meeting Notes',
        'type' => 'intake',
        'content' => 'Some notes',
        'processed_at' => now(),
    ]);

    // Simulates the child document ProcessDocumentAI creates when it processes
    // the note above: it has the same type as the next workflow step's from_key,
    // but it is not itself a root document.
    $actionItems = Document::create([
        'project_id' => $project->id,
        'parent_id' => $note->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up with the client',
    ]);

    Queue::assertNotPushed(ProcessDocumentAI::class, fn ($job) => $job->document->is($actionItems));
});
