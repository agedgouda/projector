<?php

use App\Models\Client;
use App\Models\LifecycleStep;
use App\Models\LifecycleTemplate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->org = Organization::create(['name' => 'Test Org']);
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
