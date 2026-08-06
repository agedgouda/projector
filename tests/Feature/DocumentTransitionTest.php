<?php

use App\Contracts\LlmDriver;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->template = AiTemplate::create([
        'name' => 'Action Items to Task',
        'type' => 'workflow',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);

    $this->org = Organization::create(['name' => 'Test Org', 'membership_tier' => 'friends_family']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
    ]);

    WorkflowStep::create([
        'project_type_id' => $this->projectType->id,
        'from_key' => 'action_items',
        'to_key' => 'task',
        'ai_template_id' => $this->template->id,
        'order' => 1,
    ]);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);

    $this->document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up with the client',
        'processed_at' => now(),
    ]);

    setPermissionsTeamId($this->org->id);
});

it('runs any chosen document type and AI template for an authorized org-admin', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $otherTemplate = AiTemplate::create([
        'name' => 'Action Items to Follow-up',
        'type' => 'workflow',
        'system_prompt' => 'Extract follow-ups.',
        'user_prompt' => '{{input}}',
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'followup',
            'ai_template_id' => $otherTemplate->id,
        ])
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document)
            && $job->overrideStep['to_key'] === 'followup'
            && $job->overrideStep['ai_template_id'] === $otherTemplate->id
    );

    expect($this->document->fresh()->processed_at)->toBeNull();
});

it('rejects a second transition request for the same document while the first is still processing', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
        ])
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
        ])
        ->assertStatus(409);

    Queue::assertPushed(ProcessDocumentAI::class, 1);
});

it('passes one_off_instructions from the reprocess request through to the job, without persisting it anywhere', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]), [
            'one_off_instructions' => 'Only extract from the section labeled "Action Items" this time.',
        ])
        ->assertSuccessful();

    Queue::assertPushed(ProcessDocumentAI::class, function ($job) {
        return $job->document->is($this->document)
            && $job->oneOffInstructions === 'Only extract from the section labeled "Action Items" this time.';
    });

    // Ephemeral — never written to the document itself.
    expect($this->document->fresh()->getAttributes())->not->toHaveKey('one_off_instructions');
});

it('reprocesses without one_off_instructions when none is given', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    Queue::assertPushed(ProcessDocumentAI::class, fn ($job) => $job->oneOffInstructions === null);
});

it('rejects a second reprocess request for the same document while the first is still processing', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertStatus(409);

    Queue::assertPushed(ProcessDocumentAI::class, 1);
});

it('allows a new transition once the previous run has actually finished', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Do the thing', 'task' => 'Follow up', 'criteria' => []],
            ],
        ]);

    // QUEUE_CONNECTION=sync in tests, so this POST runs ProcessDocumentAI::handle() inline,
    // releasing the lock by the time the response comes back.
    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
        ])
        ->assertSuccessful();

    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    Queue::assertPushed(ProcessDocumentAI::class, 1);
});

it('derives to_key from the AI template name when to_key is omitted', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $freeformTemplate = AiTemplate::create([
        'name' => 'Todo List',
        'type' => 'workflow',
        'system_prompt' => 'Extract a todo list.',
        'user_prompt' => '{{input}}',
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'ai_template_id' => $freeformTemplate->id,
        ])
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document)
            && $job->overrideStep['to_key'] === 'todo_list'
            && $job->overrideStep['ai_template_id'] === $freeformTemplate->id
    );
});

it('uses the template\'s own output_key when to_key is omitted, instead of slugifying its name', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $templateWithOutputKey = AiTemplate::create([
        'name' => 'Whatever This Is Called',
        'type' => 'workflow',
        'output_key' => 'task',
        'system_prompt' => 'Extract tasks.',
        'user_prompt' => '{{input}}',
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'ai_template_id' => $templateWithOutputKey->id,
        ])
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document)
            && $job->overrideStep['to_key'] === 'task'
            && $job->overrideStep['ai_template_id'] === $templateWithOutputKey->id
    );
});

it('never consults workflow_steps for a direct pick, even when the template is also used by an unrelated protocol with a different to_key', function () {
    Queue::fake([ProcessDocumentAI::class]);

    // $this->template has a WorkflowStep mapping it to 'task' for $this->projectType, but no
    // output_key of its own — a direct pick must not go looking at that workflow_steps row.
    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'ai_template_id' => $this->template->id,
        ])
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document)
            && $job->overrideStep['to_key'] === 'action_items_to_task'
            && $job->overrideStep['ai_template_id'] === $this->template->id
    );
});

it('passes project_type_id through to the job when a protocol-driven transition is chosen', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
            'project_type_id' => $this->projectType->id,
        ])
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document)
            && $job->overrideStep['project_type_id'] === $this->projectType->id
    );
});

it('locks the resulting child to the chosen protocol', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Do the thing', 'task' => 'Follow up', 'criteria' => []],
            ],
        ]);

    // QUEUE_CONNECTION=sync in tests, so this POST runs ProcessDocumentAI::handle() inline.
    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
            'project_type_id' => $this->projectType->id,
        ])
        ->assertSuccessful();

    $child = Document::where('parent_id', $this->document->id)->sole();
    expect($child->locked_project_type_id)->toBe($this->projectType->id);
});

it('does not lock the resulting child when a raw AI template is chosen instead of a protocol', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Do the thing', 'task' => 'Follow up', 'criteria' => []],
            ],
        ]);

    (new ProcessDocumentAI($this->document, [
        'to_key' => 'task',
        'ai_template_id' => $this->template->id,
    ]))->handle();

    $child = Document::where('parent_id', $this->document->id)->sole();
    expect($child->locked_project_type_id)->toBeNull();
});

it('reprocessing a locked document uses the locked protocol\'s own next step automatically', function () {
    $this->document->update(['locked_project_type_id' => $this->projectType->id]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Do the thing', 'task' => 'Follow up', 'criteria' => []],
            ],
        ]);

    // QUEUE_CONNECTION=sync in tests, so this POST runs ProcessDocumentAI::handle() inline.
    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    $child = Document::where('parent_id', $this->document->id)->sole();
    expect($child->type)->toBe('task');
    expect($child->locked_project_type_id)->toBe($this->projectType->id);
});

it('reprocessing a document previously transformed via a direct pick re-runs that exact same template', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Do the thing', 'task' => 'Follow up', 'criteria' => []],
            ],
        ]);

    // QUEUE_CONNECTION=sync in tests, so this POST runs ProcessDocumentAI::handle() inline,
    // recording last_ai_template_id/last_output_key on $this->document.
    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
        ])
        ->assertSuccessful();

    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document)
            && $job->overrideStep['to_key'] === 'task'
            && $job->overrideStep['ai_template_id'] === $this->template->id
            && $job->overrideStep['project_type_id'] === null
    );
});

it('reprocess has no override step for a document that has never been transformed', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->document->is($this->document) && $job->overrideStep === null
    );
});

it('rejects an ai_template_id that does not exist', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => 999999,
        ])
        ->assertSessionHasErrors('ai_template_id');

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('blocks the transition once the free tier ai_docs limit is reached', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $this->org->update(['membership_tier' => 'free']);
    collect(range(1, 10))->each(fn () => AiUsageLog::create([
        'organization_id' => $this->org->id,
        'driver' => 'test-driver',
        'model' => 'test-model',
        'type' => 'llm',
        'created_at' => now(),
    ]));

    $this->actingAs($this->admin)
        ->post(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->template->id,
        ]);

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('exposes whether a locked document\'s type has a further step in its locked protocol', function () {
    $lockedWithNextStep = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items 2',
        'type' => 'action_items',
        'content' => 'Follow up',
        'processed_at' => now(),
        'locked_project_type_id' => $this->projectType->id,
    ]);

    $lockedTerminal = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Task 1',
        'type' => 'task',
        'content' => 'Do it',
        'processed_at' => now(),
        'locked_project_type_id' => $this->projectType->id,
    ]);

    $documents = Document::query()
        ->whereIn('id', [$lockedWithNextStep->id, $lockedTerminal->id])
        ->withExists('lockedNextWorkflowStep')
        ->get()
        ->keyBy('id');

    expect($documents[$lockedWithNextStep->id]->locked_next_workflow_step_exists)->toBeTrue();
    expect($documents[$lockedTerminal->id]->locked_next_workflow_step_exists)->toBeFalse();
});

it('returns protocols with a next step from this document\'s type, and workflow ai templates', function () {
    $orgTemplate = AiTemplate::create([
        'name' => 'Org Extraction',
        'type' => 'org_extraction',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
    ]);

    // A protocol with no step for "action_items" should not be offered.
    ProjectType::factory()->create(['name' => 'Unrelated Protocol']);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.documents.transitionOptions', [$this->project, $this->document]))
        ->assertSuccessful();

    $protocolOptions = collect($response->json('protocolOptions'));
    expect($protocolOptions)->toHaveCount(1);
    expect($protocolOptions->first())
        ->toMatchArray([
            'projectTypeId' => $this->projectType->id,
            'name' => $this->projectType->name,
            'toKey' => 'task',
            'aiTemplateId' => $this->template->id,
            'singleOutput' => false,
        ]);

    $templateIds = collect($response->json('aiTemplates'))->pluck('id');
    expect($templateIds)->toContain($this->template->id);
    expect($templateIds)->not->toContain($orgTemplate->id);
});

it('excludes the universal intake -> action_items template from the ai template picker', function () {
    $universalTemplate = AiTemplate::create([
        'name' => 'Notes to Action Items',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $universalTemplate->id]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.documents.transitionOptions', [$this->project, $this->document]))
        ->assertSuccessful();

    $templateIds = collect($response->json('aiTemplates'))->pluck('id');
    expect($templateIds)->not->toContain($universalTemplate->id);
    expect($templateIds)->toContain($this->template->id);
});
