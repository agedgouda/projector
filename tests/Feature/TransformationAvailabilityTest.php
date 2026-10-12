<?php

use App\Contracts\VectorDriver;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
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
    $this->app->bind(VectorDriver::class, fn () => new class implements VectorDriver
    {
        public function getEmbedding(string $text): array
        {
            return array_fill(0, 1536, 0.01);
        }
    });

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Org A', 'membership_tier' => 'friends_family']);
    $this->otherOrg = Organization::create(['name' => 'Org B', 'membership_tier' => 'friends_family']);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'team-member']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->workflow = AiTemplate::create(['name' => 'Create Tasks', 'type' => 'workflow', 'output_key' => 'task', 'system_prompt' => 's', 'user_prompt' => '{{input}}']);
    $this->otherWorkflow = AiTemplate::create(['name' => 'Create Stories', 'type' => 'workflow', 'output_key' => 'story', 'system_prompt' => 's', 'user_prompt' => '{{input}}']);
    $this->otherOrgWorkflow = AiTemplate::create(['name' => 'Org B Only', 'type' => 'workflow', 'organization_id' => $this->otherOrg->id, 'system_prompt' => 's', 'user_prompt' => '{{input}}']);

    $this->projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
            ['label' => 'Task', 'key' => 'task', 'is_task' => true],
        ],
    ]);
    WorkflowStep::create([
        'project_type_id' => $this->projectType->id,
        'from_key' => 'action_items',
        'to_key' => 'task',
        'ai_template_id' => $this->workflow->id,
        'order' => 1,
    ]);

    $client = Client::create(['organization_id' => $this->org->id, 'company_name' => 'Client Co', 'contact_name' => 'Jane Doe', 'contact_phone' => '555-1234']);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);
    $this->document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Follow up with the client',
        'processed_at' => now(),
    ]);

    setPermissionsTeamId($this->org->id);
});

function turnOff(AiTemplate $template, Organization $organization): void
{
    $organization->disabledAiTemplates()->attach($template->id);
}

it('lets an org admin turn a transformation off and back on for their organization', function () {
    $this->actingAs($this->admin)
        ->putJson(route('transformation-library.availability', $this->workflow), ['enabled' => false])
        ->assertOk();

    expect($this->workflow->isDisabledFor($this->org->id))->toBeTrue()
        ->and($this->workflow->isDisabledFor($this->otherOrg->id))->toBeFalse();

    $this->actingAs($this->admin)
        ->putJson(route('transformation-library.availability', $this->workflow), ['enabled' => true])
        ->assertOk();

    expect($this->workflow->isDisabledFor($this->org->id))->toBeFalse();
});

it('lets a super admin turn a transformation off for their current organization', function () {
    $this->actingAs($this->superAdmin)
        ->withSession(['active_org_id' => $this->org->id])
        ->putJson(route('transformation-library.availability', $this->workflow), ['enabled' => false])
        ->assertOk();

    expect($this->workflow->isDisabledFor($this->org->id))->toBeTrue();
});

it('does not let a regular member turn a transformation off', function () {
    // The whole Transformation Library is org-admin only; the org-role middleware 404s anyone else.
    $this->actingAs($this->member)
        ->putJson(route('transformation-library.availability', $this->workflow), ['enabled' => false])
        ->assertNotFound();

    expect($this->workflow->isDisabledFor($this->org->id))->toBeFalse();
});

it('requires an on/off value', function () {
    $this->actingAs($this->admin)
        ->putJson(route('transformation-library.availability', $this->workflow), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('enabled');
});

it('refuses to turn off a transformation that runs automatically or belongs to another organization', function (Closure $makeTemplate) {
    $template = $makeTemplate($this);

    $this->actingAs($this->superAdmin)
        ->withSession(['active_org_id' => $this->org->id])
        ->putJson(route('transformation-library.availability', $template), ['enabled' => false])
        ->assertForbidden();

    expect($template->isDisabledFor($this->org->id))->toBeFalse();
})->with([
    'status meeting extraction' => fn ($test) => AiTemplate::create(['name' => 'Status', 'type' => 'org_extraction', 'system_prompt' => 's', 'user_prompt' => 'u']),
    'system classification' => fn ($test) => AiTemplate::create(['name' => 'Classify', 'type' => 'text_extraction_classification', 'system_prompt' => 's', 'user_prompt' => 'u']),
    'notes to action items' => function ($test) {
        $template = AiTemplate::create(['name' => 'Intake', 'type' => 'workflow', 'system_prompt' => 's', 'user_prompt' => 'u']);
        config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

        return $template;
    },
    'another organization\'s' => fn ($test) => $test->otherOrgWorkflow,
]);

it('leaves a turned-off transformation out of the Transform picker, for that organization only', function () {
    turnOff($this->workflow, $this->org);

    $names = $this->actingAs($this->admin)
        ->getJson(route('projects.documents.transitionOptions', [$this->project, $this->document]))
        ->assertOk()
        ->json('aiTemplates.*.name');

    expect($names)->toContain('Create Stories')->not->toContain('Create Tasks');

    $this->workflow->disabledForOrganizations()->detach();
    turnOff($this->workflow, $this->otherOrg);

    $names = $this->actingAs($this->admin)
        ->getJson(route('projects.documents.transitionOptions', [$this->project, $this->document]))
        ->json('aiTemplates.*.name');

    expect($names)->toContain('Create Stories', 'Create Tasks');
});

it('rejects running a turned-off transformation directly', function () {
    Queue::fake([ProcessDocumentAI::class]);
    turnOff($this->workflow, $this->org);

    $this->actingAs($this->admin)
        ->postJson(route('projects.documents.transition', [$this->project, $this->document]), [
            'ai_template_id' => $this->workflow->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ai_template_id');

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('still runs a turned-off transformation as a protocol step', function () {
    Queue::fake([ProcessDocumentAI::class]);
    turnOff($this->workflow, $this->org);

    $this->actingAs($this->admin)
        ->postJson(route('projects.documents.transition', [$this->project, $this->document]), [
            'to_key' => 'task',
            'ai_template_id' => $this->workflow->id,
            'project_type_id' => $this->projectType->id,
        ])
        ->assertSuccessful();

    Queue::assertPushed(ProcessDocumentAI::class);
});

it('still reprocesses a document whose last transformation was turned off', function () {
    Queue::fake([ProcessDocumentAI::class]);
    $this->document->update(['last_ai_template_id' => $this->workflow->id, 'last_output_key' => 'task']);
    turnOff($this->workflow, $this->org);

    $this->actingAs($this->admin)
        ->postJson(route('projects.documents.reprocess', [$this->project, $this->document]))
        ->assertSuccessful();

    Queue::assertPushed(
        ProcessDocumentAI::class,
        fn ($job) => $job->overrideStep['ai_template_id'] === $this->workflow->id
    );
});

it('leaves a turned-off saved import transformation out of Import Data, and rejects applying it', function () {
    $saved = AiTemplate::create([
        'name' => 'Marketing Sheet',
        'type' => 'spreadsheet_import',
        'organization_id' => $this->org->id,
        'import_config' => ['passes' => [['list_type' => 'event', 'mapping' => ['name' => 'Name']]]],
    ]);
    $kept = AiTemplate::create(['name' => 'Budget Sheet', 'type' => 'spreadsheet_import', 'organization_id' => $this->org->id]);
    turnOff($saved, $this->org);

    $names = $this->actingAs($this->admin)
        ->getJson(route('import-transformations.index'))
        ->assertOk()
        ->json('transformations.*.name');

    expect($names)->toContain($kept->name)->not->toContain($saved->name);

    $this->actingAs($this->admin)
        ->postJson(route('projects.import-transformations.apply', $this->project), [
            'headers' => ['Name'],
            'rows' => [['Kickoff']],
            'ai_template_id' => $saved->id,
            'passes' => [['list_type' => 'event', 'mapping' => ['name' => 'Name']]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ai_template_id');
});

it('shows org admins and super admins a switch on the library cards', function () {
    turnOff($this->workflow, $this->org);

    $adminTemplates = collect($this->actingAs($this->admin)
        ->get(route('transformation-library.index'))
        ->viewData('page')['props']['templates'])->keyBy('name');

    expect($adminTemplates['Create Tasks']['can_toggle'])->toBeTrue()
        ->and($adminTemplates['Create Tasks']['is_enabled'])->toBeFalse()
        ->and($adminTemplates['Create Stories']['is_enabled'])->toBeTrue();

    $superAdminTemplates = collect($this->actingAs($this->superAdmin)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('transformation-library.index'))
        ->viewData('page')['props']['templates'])->keyBy('name');

    expect($superAdminTemplates['Create Tasks']['can_toggle'])->toBeTrue()
        ->and($superAdminTemplates['Create Tasks']['is_enabled'])->toBeFalse()
        ->and($superAdminTemplates['Org B Only']['can_toggle'])->toBeFalse();
});

it('only offers protocol steps global and the organization\'s own transformations, marking turned-off ones', function () {
    turnOff($this->workflow, $this->org);

    $options = collect($this->actingAs($this->admin)
        ->get(route('project-types.create'))
        ->viewData('page')['props']['aiTemplates'])->keyBy('name');

    expect($options->keys()->all())->not->toContain('Org B Only')
        ->and($options['Create Tasks']['disabled_organization_ids'])->toBe([$this->org->id])
        ->and($options['Create Stories']['disabled_organization_ids'])->toBe([]);

    $superAdminOptions = collect($this->actingAs($this->superAdmin)
        ->get(route('project-types.create'))
        ->viewData('page')['props']['aiTemplates'])->pluck('name');

    expect($superAdminOptions->all())->toContain('Org B Only');
});
