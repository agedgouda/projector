<?php

use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
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
    ]);

    return Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
        'project_type_id' => $projectType->id,
    ]);
}

it('dispatches the universal intake -> action_items transition for a root Notes document', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $project = createProjectWithChainedWorkflow();

    $note = $project->documents()->create([
        'name' => 'Meeting Notes',
        'type' => 'intake',
        'content' => 'Some notes',
    ]);

    // No override is passed — ProjectAiService::process() detects the intake type itself and
    // applies the universal step (see the ProjectAiService tests for that behavior directly).
    Queue::assertPushed(ProcessDocumentAI::class, fn ($job) => $job->document->is($note) && $job->overrideStep === null);
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

it('does not auto-dispatch for a root document of a non-intake type', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $project = createProjectWithChainedWorkflow();

    // A root "action_items" document (e.g. created directly, not via the intake step) — under
    // the new design nothing past the universal intake step ever fires automatically.
    $actionItems = $project->documents()->create([
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up with the client',
    ]);

    Queue::assertNotPushed(ProcessDocumentAI::class, fn ($job) => $job->document->is($actionItems));
});

it('defaults task_status to todo using the global catalog, even for a type not in the project\'s own protocol', function () {
    $project = createProjectWithChainedWorkflow();

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'user_story',
        'label' => 'User Story',
        'is_task' => true,
        'order' => 1,
    ]);

    $document = $project->documents()->create([
        'name' => 'A cross-protocol document',
        'type' => 'user_story',
        'content' => 'As a user...',
    ]);

    expect($document->task_status)->toBe('todo');
});
