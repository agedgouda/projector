<?php

use App\Models\Client;
use App\Models\LifecycleStep;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->projectType = ProjectType::factory()->create();
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);
    $this->step = LifecycleStep::create([
        'project_type_id' => $this->projectType->id,
        'order' => 1,
        'label' => 'Intake',
        'color' => 'indigo',
    ]);

    $orgAdminRole = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    setPermissionsTeamId($this->org->id);
    $this->admin->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->admin->assignRole($orgAdminRole);

    $this->stranger = User::factory()->create();
});

it('allows an org-admin to update the lifecycle step', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.lifecycle-step', $this->project), [
            'current_lifecycle_step_id' => $this->step->id,
        ])
        ->assertRedirect();

    expect($this->project->fresh()->current_lifecycle_step_id)->toBe($this->step->id);
});

it('allows clearing the lifecycle step by sending null', function () {
    $this->project->update(['current_lifecycle_step_id' => $this->step->id]);

    $this->actingAs($this->admin)
        ->patch(route('projects.lifecycle-step', $this->project), [
            'current_lifecycle_step_id' => null,
        ])
        ->assertRedirect();

    expect($this->project->fresh()->current_lifecycle_step_id)->toBeNull();
});

it('rejects an invalid lifecycle step id', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.lifecycle-step', $this->project), [
            'current_lifecycle_step_id' => 99999,
        ])
        ->assertSessionHasErrors('current_lifecycle_step_id');
});

it('forbids a user from another org from updating the lifecycle step', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherAdmin = User::factory()->create();
    setPermissionsTeamId($otherOrg->id);
    $otherAdmin->organizations()->syncWithoutDetaching([$otherOrg->id]);
    $otherAdmin->assignRole(Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']));

    $this->actingAs($otherAdmin)
        ->patch(route('projects.lifecycle-step', $this->project), [
            'current_lifecycle_step_id' => $this->step->id,
        ])
        ->assertNotFound();
});

it('redirects guests to login', function () {
    $this->patch(route('projects.lifecycle-step', $this->project), [
        'current_lifecycle_step_id' => $this->step->id,
    ])->assertRedirectContains('login');
});

it('nulls the current step when the lifecycle step is deleted', function () {
    $this->project->update(['current_lifecycle_step_id' => $this->step->id]);

    $this->step->delete();

    expect($this->project->fresh()->current_lifecycle_step_id)->toBeNull();
});
