<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->user = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->project = Project::create([
        'name' => 'Mobile Redesign',
        'client_id' => $this->client->id,
    ]);
});

it('lists projects visible to the authenticated user', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.projects.index'));

    $response->assertOk();
    expect($response->json('projects'))->toHaveCount(1);
    expect($response->json('projects.0.name'))->toBe('Mobile Redesign');
    expect($response->json('projects.0.client_name'))->toBe('Client Co');
});

it('does not list projects from another organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    Project::create([
        'name' => 'Other Project',
        'client_id' => $otherClient->id,
    ]);

    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.projects.index'));

    expect($response->json('projects'))->toHaveCount(1);
});

it('excludes inactive clients projects', function () {
    $this->client->update(['inactive' => true]);

    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.projects.index'));

    expect($response->json('projects'))->toHaveCount(0);
});
