<?php

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org', 'membership_tier' => 'pro']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->orgAdmin = User::factory()->create();
    $this->org->users()->attach($this->orgAdmin->id, ['role' => 'org-admin']);

    $this->target = User::factory()->create();
});

it('allows a super-admin to add a user to an organization', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('organizations.users.store', $this->org), ['user_id' => $this->target->id])
        ->assertRedirect();

    expect($this->org->users()->where('user_id', $this->target->id)->exists())->toBeTrue();
});

it('returns an error when adding a user who is already a member', function () {
    $this->org->users()->attach($this->target->id);

    $this->actingAs($this->superAdmin)
        ->post(route('organizations.users.store', $this->org), ['user_id' => $this->target->id])
        ->assertSessionHasErrors('user_id');

    expect($this->org->users()->where('user_id', $this->target->id)->count())->toBe(1);
});

it('rejects a non-existent user_id', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('organizations.users.store', $this->org), ['user_id' => 99999])
        ->assertSessionHasErrors('user_id');
});

it('requires user_id', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('organizations.users.store', $this->org), [])
        ->assertSessionHasErrors('user_id');
});

it('allows an org-admin to add users to their organization', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.users.store', $this->org), ['user_id' => $this->target->id])
        ->assertRedirect();

    expect($this->org->users()->where('user_id', $this->target->id)->exists())->toBeTrue();
});

it('forbids a regular user from adding users to an organization', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->post(route('organizations.users.store', $this->org), ['user_id' => $this->target->id])
        ->assertNotFound();
});

it('shows a super-admin in the organization\'s own team list once added, not only under System Administration', function () {
    $this->org->users()->attach($this->superAdmin->id, ['role' => 'team-member']);

    $response = $this->actingAs($this->orgAdmin)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('organizations.index', ['org' => $this->org->id]));

    $response->assertOk();

    $memberIds = collect($response->original->getData()['page']['props']['currentOrg']['users'])->pluck('id')->all();
    expect($memberIds)->toContain($this->superAdmin->id);

    $superAdminRow = collect($response->original->getData()['page']['props']['currentOrg']['users'])
        ->firstWhere('id', $this->superAdmin->id);
    expect($superAdminRow['is_super'])->toBeTrue();
});

it('still buckets a super-admin with no organization memberships under System Administration, not any specific org', function () {
    $response = $this->actingAs($this->orgAdmin)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('organizations.index', ['org' => $this->org->id]));

    $response->assertOk();

    $memberIds = collect($response->original->getData()['page']['props']['currentOrg']['users'])->pluck('id')->all();
    expect($memberIds)->not->toContain($this->superAdmin->id);
});
