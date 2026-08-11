<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Acme Corp',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Website Redesign',
        'client_id' => $this->client->id,
    ]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
});

// ProjectFolio.vue's "Add Sub-project" link reads project.client.company_name for every
// project rendered under a client — if that's missing, Vue throws mid-render and the whole
// project list silently fails to display (reported as "nothing shows when I expand a client").

it('includes the project\'s client on the organization profile Clients tab', function () {
    $response = $this->actingAs($this->admin)->get(route('organizations.index'));

    $response->assertOk();

    $clients = collect($response->original->getData()['page']['props']['clients']);
    $client = $clients->firstWhere('id', $this->client->id);
    $project = collect($client['projects'])->firstWhere('id', $this->project->id);

    expect($project['client']['company_name'] ?? null)->toBe('Acme Corp');
});

it('includes the project\'s client on the clients index page', function () {
    $response = $this->actingAs($this->admin)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('clients.index'));

    $response->assertOk();

    $clients = collect($response->original->getData()['page']['props']['clients']);
    $client = $clients->firstWhere('id', $this->client->id);
    $project = collect($client['projects'])->firstWhere('id', $this->project->id);

    expect($project['client']['company_name'] ?? null)->toBe('Acme Corp');
});
