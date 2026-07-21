<?php

use App\Models\Client;
use App\Models\LifecycleStep;
use App\Models\LifecycleTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->org = Organization::create(['name' => 'Test Org']);
});

it('creates a matching LifecycleTemplate with steps when storing a project type', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('project-types.store'), [
            'name' => 'New Protocol',
            'icon' => 'Briefcase',
            'organization_id' => $this->org->id,
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [
                ['order' => 1, 'label' => 'Intake', 'color' => 'indigo'],
                ['order' => 2, 'label' => 'Done', 'color' => 'green'],
            ],
        ])
        ->assertRedirect();

    $template = LifecycleTemplate::where('organization_id', $this->org->id)->where('name', 'New Protocol')->firstOrFail();

    expect($template->lifecycleSteps)->toHaveCount(2)
        ->and($template->lifecycleSteps->pluck('label')->toArray())->toBe(['Intake', 'Done']);
});

it('creates a matching LifecycleTemplate when duplicating a project type', function () {
    $source = ProjectType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Source Protocol']);
    LifecycleStep::create(['project_type_id' => $source->id, 'order' => 1, 'label' => 'Backlog', 'color' => 'slate']);

    $targetOrg = Organization::create(['name' => 'Target Org']);

    $this->actingAs($this->superAdmin)
        ->post(route('project-types.duplicate', $source), ['organization_id' => $targetOrg->id])
        ->assertRedirect();

    $copy = ProjectType::where('organization_id', $targetOrg->id)->where('name', 'Source Protocol')->firstOrFail();
    $template = LifecycleTemplate::where('organization_id', $targetOrg->id)->where('name', 'Source Protocol')->firstOrFail();

    expect($copy->lifecycleSteps)->toHaveCount(1);
    expect($template->lifecycleSteps)->toHaveCount(1)
        ->and($template->lifecycleSteps->first()->label)->toBe('Backlog');
});

it('auto-assigns a matching lifecycle_template_id when creating a project', function () {
    $projectType = ProjectType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Support Protocol']);
    LifecycleStep::create(['project_type_id' => $projectType->id, 'lifecycle_template_id' => null, 'order' => 1, 'label' => 'Open', 'color' => 'blue']);

    // Simulate the template already existing (as it would from the protocol editor).
    $template = LifecycleTemplate::create(['organization_id' => $this->org->id, 'name' => 'Support Protocol']);
    LifecycleStep::where('project_type_id', $projectType->id)->update(['lifecycle_template_id' => $template->id]);

    $client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->org->users()->attach($this->superAdmin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->superAdmin)
        ->post(route('projects.store'), [
            'name' => 'New Project',
            'client_id' => $client->id,
            'project_type_id' => $projectType->id,
        ])
        ->assertRedirect();

    $project = Project::where('name', 'New Project')->firstOrFail();
    expect($project->lifecycle_template_id)->toBe($template->id);
});

it('nulls the project\'s current lifecycle step when that step is deleted', function () {
    $template = LifecycleTemplate::factory()->create();
    $step = LifecycleStep::create(['lifecycle_template_id' => $template->id, 'order' => 1, 'label' => 'Intake', 'color' => 'indigo']);

    $client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
        'lifecycle_template_id' => $template->id,
        'current_lifecycle_step_id' => $step->id,
    ]);

    $step->delete();

    expect($project->fresh()->current_lifecycle_step_id)->toBeNull();
});
