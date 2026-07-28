<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
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

    $this->projectType = ProjectType::factory()->create();

    $this->project = Project::create([
        'name' => 'Mobile Redesign',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);
});

it('does not redirect a single-organization user to the picker', function () {
    $this->actingAs($this->user)
        ->get(route('mobile.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Dashboard')
            ->where('canSwitchOrganization', false)
        );
});

it('redirects a multi-organization user with no saved choice to the organization picker', function () {
    $secondOrg = Organization::create(['name' => 'Beta LLC']);
    $secondOrg->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->actingAs($this->user)
        ->get(route('mobile.dashboard'))
        ->assertRedirect(route('mobile.organizations.index'));
});

it('does not redirect a multi-organization user who already has a saved choice', function () {
    $secondOrg = Organization::create(['name' => 'Beta LLC']);
    $secondOrg->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->actingAs($this->user)
        ->withCookie('last_org_id', $this->org->id)
        ->get(route('mobile.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Dashboard')
            ->where('canSwitchOrganization', true)
        );
});

it('redirects a super-admin with no saved choice to the organization picker, even with only one org attached', function () {
    $this->user->assignRole('super-admin');

    $this->actingAs($this->user)
        ->get(route('mobile.dashboard'))
        ->assertRedirect(route('mobile.organizations.index'));
});

it('lists every organization for a super-admin on the mobile picker, not just attached ones', function () {
    $this->user->assignRole('super-admin');
    Organization::create(['name' => 'Unrelated Org']);

    $response = $this->actingAs($this->user)
        ->get(route('mobile.organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Organizations/Index')
            ->has('organizations', 2)
        );
});

it('lists only attached organizations for a regular multi-org user on the mobile picker', function () {
    $secondOrg = Organization::create(['name' => 'Beta LLC']);
    $secondOrg->users()->attach($this->user->id, ['role' => 'org-admin']);
    Organization::create(['name' => 'Unrelated Org']);

    $response = $this->actingAs($this->user)
        ->get(route('mobile.organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Organizations/Index')
            ->has('organizations', 2)
        );

    $names = collect($response->original->getData()['page']['props']['organizations'])->pluck('name')->all();
    expect($names)->toEqualCanonicalizing(['Acme Inc', 'Beta LLC']);
});

it('saves the chosen organization as a forever cookie and redirects to the dashboard', function () {
    $secondOrg = Organization::create(['name' => 'Beta LLC']);
    $secondOrg->users()->attach($this->user->id, ['role' => 'org-admin']);

    $response = $this->actingAs($this->user)
        ->post(route('mobile.organizations.store'), ['organization_id' => $secondOrg->id]);

    $response->assertRedirect(route('mobile.dashboard'));
    $response->assertCookie('last_org_id', $secondOrg->id);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'last_org_id');
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addYear()->timestamp);
});

it('rejects picking an organization the user cannot access', function () {
    $otherOrg = Organization::create(['name' => 'Not Mine']);

    $this->actingAs($this->user)
        ->post(route('mobile.organizations.store'), ['organization_id' => $otherOrg->id])
        ->assertNotFound();
});
