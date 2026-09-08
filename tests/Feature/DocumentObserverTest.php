<?php

use App\Events\DocumentProcessingUpdate;
use App\Jobs\GenerateDocumentEmbedding;
use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    // Seeds the global document type catalog (via ProjectTypeObserver) with the types this
    // test exercises - unrelated to any specific project's protocol, since projects no longer
    // have one.
    ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
    ]);

    return Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
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

it('dispatches embedding generation when a document\'s content is updated', function () {
    Queue::fake([GenerateDocumentEmbedding::class]);

    $project = createProjectWithChainedWorkflow();
    $document = $project->documents()->create([
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Original content',
        'processed_at' => now(),
    ]);
    Queue::fake([GenerateDocumentEmbedding::class]);

    $document->update(['content' => 'Updated content']);

    Queue::assertPushed(GenerateDocumentEmbedding::class, fn ($job) => $job->document->is($document));
});

it('dispatches embedding generation for a content update made inside a DB transaction', function () {
    // Regression test: DocumentObserver implements ShouldHandleEventsAfterCommit, so its
    // updated() listener only actually runs after the enclosing transaction commits — by
    // which point save() has already synced the model's original attributes. A check using
    // isDirty() (rather than wasChanged()) would read false by then and silently never
    // dispatch, exactly the bug this covers (see ProcessDocumentAI's single-output reuse,
    // which updates an existing document's content from inside DB::transaction()).
    Queue::fake([GenerateDocumentEmbedding::class]);

    $project = createProjectWithChainedWorkflow();
    $document = $project->documents()->create([
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Original content',
        'processed_at' => now(),
    ]);
    Queue::fake([GenerateDocumentEmbedding::class]);

    DB::transaction(function () use ($document) {
        $document->update(['content' => 'Updated content']);
    });

    Queue::assertPushed(GenerateDocumentEmbedding::class, fn ($job) => $job->document->is($document));
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

it('preserves an explicitly-given task_status instead of resetting it to todo', function () {
    // Regression test: the default-to-'todo' check above used to key off the legacy `status`
    // column (see DocumentController.php's store()), which nothing ever sets — so it was
    // always null and unconditionally overwrote task_status back to 'todo' on every creation,
    // silently discarding whatever status the caller actually asked for.
    $project = createProjectWithChainedWorkflow();

    $document = $project->documents()->create([
        'name' => 'A task created with a non-default status',
        'type' => 'task',
        'content' => 'Some content',
        'task_status' => 'done',
    ]);

    expect($document->task_status)->toBe('done');
});

// ── Live Kanban broadcast for root-level tasks ──────────────────────────────

it('stamps processed_at on a root-level task, since it is never waiting on an AI step', function () {
    $project = createProjectWithChainedWorkflow();

    $document = $project->documents()->create([
        'name' => 'A manually entered task',
        'type' => 'task',
        'content' => 'Some content',
    ]);

    expect($document->processed_at)->not->toBeNull();
});

it('broadcasts a DocumentProcessingUpdate for a root-level task creation', function () {
    Event::fake([DocumentProcessingUpdate::class]);

    $project = createProjectWithChainedWorkflow();

    $document = $project->documents()->create([
        'name' => 'A manually entered task',
        'type' => 'task',
        'content' => 'Some content',
    ]);

    Event::assertDispatched(DocumentProcessingUpdate::class, fn ($event) => $event->document->is($document)
        && $event->statusMessage === ''
        && $event->newDocumentCount === 0);
});

it('broadcasts a DocumentProcessingUpdate on both the project and organization channels', function () {
    $project = createProjectWithChainedWorkflow();

    $document = $project->documents()->create([
        'name' => 'A manually entered task',
        'type' => 'task',
        'content' => 'Some content',
    ]);

    $channelNames = collect((new DocumentProcessingUpdate($document, 'Processing...'))->broadcastOn())
        ->map(fn ($channel) => $channel->name);

    expect($channelNames)->toContain('private-project.'.$project->id)
        ->toContain('private-organization.'.$project->organization_id);
});

it('does not broadcast or stamp processed_at for a non-task document', function () {
    Event::fake([DocumentProcessingUpdate::class]);

    $project = createProjectWithChainedWorkflow();

    $note = $project->documents()->create([
        'name' => 'Meeting Notes',
        'type' => 'intake',
        'content' => 'Some notes',
        'processed_at' => now(),
    ]);

    // Scoped to statusMessage === '' (this observer's own signature) rather than "no
    // DocumentProcessingUpdate for this document at all" — GenerateDocumentEmbedding
    // legitimately broadcasts its own (differently-worded) progress events for this same note
    // once vectorization kicks in, which isn't what this test is about.
    Event::assertNotDispatched(DocumentProcessingUpdate::class, fn ($event) => $event->document->is($note) && $event->statusMessage === '');
});

it('does not broadcast or auto-stamp processed_at for an AI-generated child task', function () {
    // Mirrors "does not cascade AI processing to a workflow-generated child document" above —
    // a child task's processed_at is ProcessDocumentAI/GenerateDocumentEmbedding's own to set,
    // once that document's own generation actually finishes, not this observer's.
    Event::fake([DocumentProcessingUpdate::class]);

    $project = createProjectWithChainedWorkflow();
    $note = $project->documents()->create([
        'name' => 'Meeting Notes',
        'type' => 'intake',
        'content' => 'Some notes',
        'processed_at' => now(),
    ]);

    $childTask = Document::create([
        'project_id' => $project->id,
        'parent_id' => $note->id,
        'name' => 'Follow up with the client',
        'type' => 'task',
        'content' => 'Follow up with the client',
    ]);

    expect($childTask->processed_at)->toBeNull();
    // Scoped to statusMessage === '' — see the note above about GenerateDocumentEmbedding's own
    // legitimate broadcasts for this same document.
    Event::assertNotDispatched(DocumentProcessingUpdate::class, fn ($event) => $event->document->is($childTask) && $event->statusMessage === '');
});

it('does not overwrite an explicitly-given processed_at on a root-level task', function () {
    $project = createProjectWithChainedWorkflow();
    $explicit = now()->subDay();

    $document = $project->documents()->create([
        'name' => 'A task imported with a known processed time',
        'type' => 'task',
        'content' => 'Some content',
        'processed_at' => $explicit,
    ]);

    // toDateTimeString() rather than eq(): the DB round-trip truncates sub-second precision,
    // so an exact Carbon comparison would fail on microseconds this test never cared about.
    expect($document->processed_at->toDateTimeString())->toBe($explicit->toDateTimeString());
});
