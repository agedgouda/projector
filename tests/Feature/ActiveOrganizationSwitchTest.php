<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->orgA = Organization::create(['name' => 'A Org']);
    $this->orgB = Organization::create(['name' => 'B Org']);
    $this->clientB = Client::create([
        'organization_id' => $this->orgB->id,
        'company_name' => 'B Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->projectB = Project::create(['name' => 'B Project', 'client_id' => $this->clientB->id]);
});

function visitFromOrgA(User $user, string $url): \Illuminate\Testing\TestResponse
{
    $orgAId = test()->orgA->id;

    return test()->actingAs($user)
        ->withCookie('last_org_id', $orgAId)
        ->withSession(['active_org_id' => $orgAId])
        ->get($url);
}

it('switches the org picker to the project org on the first page for a member', function () {
    $user = User::factory()->create();
    $this->orgA->users()->attach($user->id, ['role' => 'org-admin']);
    $this->orgB->users()->attach($user->id, ['role' => 'org-admin']);

    $response = visitFromOrgA($user, route('projects.show', $this->projectB));

    $response->assertOk()->assertCookie('last_org_id', $this->orgB->id);
    expect($response->inertiaProps('auth.active_org_id'))->toBe($this->orgB->id)
        ->and($response->inertiaProps('auth.user.roles'))->toBe(['org-admin'])
        ->and(session('active_org_id'))->toBe($this->orgB->id);
});

it('switches the org picker to the project org for a super-admin outside that org', function () {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->orgA->users()->attach($user->id, ['role' => 'org-admin']);

    $response = visitFromOrgA($user, route('projects.show', $this->projectB));

    $response->assertOk();
    expect($response->inertiaProps('auth.active_org_id'))->toBe($this->orgB->id)
        ->and($response->inertiaProps('auth.user.roles'))->toBe(['super-admin'])
        ->and(session('active_org_id'))->toBe($this->orgB->id);
});

it('switches the active org when visiting a client page', function () {
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $response = visitFromOrgA($user, route('clients.show', $this->clientB));

    expect($response->inertiaProps('auth.active_org_id'))->toBe($this->orgB->id)
        ->and(session('active_org_id'))->toBe($this->orgB->id);
});

it('does not switch to an org the user cannot access', function () {
    $user = User::factory()->create();
    $this->orgA->users()->attach($user->id, ['role' => 'org-admin']);

    visitFromOrgA($user, route('projects.show', $this->projectB))->assertNotFound();

    expect(session('active_org_id'))->toBe($this->orgA->id);
});
