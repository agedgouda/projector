<?php

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    setPermissionsTeamId($this->org->id);
    $this->orgAdmin = User::factory()->create();
    $this->orgAdmin->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->orgAdmin->assignRole('org-admin');
    setPermissionsTeamId(null);

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

it('forbids an org-admin from adding users to an organization', function () {
    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.users.store', $this->org), ['user_id' => $this->target->id])
        ->assertNotFound();
});

it('forbids a regular user from adding users to an organization', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->post(route('organizations.users.store', $this->org), ['user_id' => $this->target->id])
        ->assertNotFound();
});
